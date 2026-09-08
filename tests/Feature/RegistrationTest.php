<?php

namespace Tests\Feature;

use App\Filament\Owner\Auth\Register;
use App\Filament\Owner\Auth\VerifyEmailPrompt;
use App\Jobs\ProvisionTenant;
use App\Models\Employee;
use App\Models\Owner;
use App\Models\Station;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Onboarding\RegisterOwner;
use App\Onboarding\SendVerificationMail;
use App\Tenancy\Provisioner;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Lokaler Registrierungsablauf auf isoliertem MySQL; keine echten E-Mails und keine Pilotdaten. */
class RegistrationTest extends TestCase
{
    private ?Tenant $provisionedTenant = null;

    /** Verifiziert vor allen Schreibvorgängen das getrennte Testschema und startet eine rückrollbare Transaktion. */
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Isolierte Testdatenbank erforderlich.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
        DB::connection('central')->beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->withoutVite();
        Queue::fake();
    }

    /** Entfernt nur das im isolierten End-to-End-Test erzeugte Schema samt Nutzer und rollt zentrale Daten zurück. */
    protected function tearDown(): void
    {
        tenancy()->end();
        if ($this->provisionedTenant) {
            DB::connection('provisioner')->statement('DROP DATABASE IF EXISTS '.$this->provisionedTenant->tenancy_db_name);
            DB::connection('provisioner')->statement("DROP USER IF EXISTS '".$this->provisionedTenant->tenancy_db_username."'@'%'");
        }
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    /** Ausschließlich fiktive Eingaben einschließlich einer nicht zustellbaren Testadresse. */
    private function input(): array
    {
        return ['first_name' => 'Erika', 'last_name' => 'Mustermann', 'email' => 'owner-registration@example.test',
            'password' => 'Local-test-password-123', 'passwordConfirmation' => 'Local-test-password-123',
            'company_name' => 'Testbetrieb', 'billing_street' => 'Testweg 1', 'billing_postal_code' => '01234',
            'billing_city' => 'Testort', 'billing_country_code' => 'DE', 'phone' => '', 'vat_id' => '',
            'station_name' => 'Erste Teststation', 'station_street' => 'Stationsweg 2', 'station_postal_code' => '36037',
            'station_city' => 'Fulda', 'station_country_code' => 'DE', 'test_registration' => true];
    }

    public function test_form_registers_unverified_owner_without_provisioning_or_exposing_passwords(): void
    {
        $this->mock(SendVerificationMail::class)->shouldReceive('send')->once()->with(Mockery::type(Owner::class));
        $this->get('/owner/register')->assertOk()->assertSee('Firma und Rechnungsanschrift');
        Livewire::test(Register::class)->fillForm($this->input())->call('register')->assertHasNoFormErrors()
            ->assertSet('data.password', '')->assertSet('data.passwordConfirmation', '');
        $owner = Owner::query()->where('email', $this->input()['email'])->sole();
        $this->assertNull($owner->email_verified_at);
        $this->assertTrue(Hash::check($this->input()['password'], $owner->password));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame('pending', $owner->tenant->provisioning_status);
        $this->assertDatabaseHas('tenant_billing_profiles', ['tenant_id' => $owner->tenant_id, 'postal_code' => '01234'], 'central');
        $this->assertDatabaseHas('registration_requests', ['tenant_id' => $owner->tenant_id, 'is_test_registration' => true], 'central');
        $this->assertDatabaseMissing('subscriptions', ['tenant_id' => $owner->tenant_id], 'central');
        Queue::assertNothingPushed();
        $this->get('/owner/overview')->assertRedirect(route('filament.owner.auth.email-verification.prompt'));
    }

    public function test_invalid_form_creates_nothing_and_clears_passwords(): void
    {
        $count = Owner::query()->count();
        Livewire::test(Register::class)->fillForm(array_replace($this->input(), ['billing_street' => '', 'passwordConfirmation' => 'different']))
            ->call('register')->assertHasFormErrors(['billing_street', 'password'])->assertSet('data.password', '');
        $this->assertSame($count, Owner::query()->count());
    }

    public function test_internal_references_and_verification_cannot_be_supplied_by_the_browser(): void
    {
        $owner = app(RegisterOwner::class)->create($this->input() + ['tenant_id' => 'attacker', 'email_verified_at' => now(), 'gross_cents' => 0]);
        $this->assertNotSame('attacker', $owner->tenant_id);
        $this->assertNull($owner->email_verified_at);
        $this->assertMatchesRegularExpression('/^sd_t_[a-f0-9]{32}$/D', $owner->tenant->tenancy_db_name);
        $this->assertNotSame($owner->tenant->tenancy_db_password, DB::connection('central')->table('tenants')->where('id', $owner->tenant_id)->value('tenancy_db_password'));
    }

    public function test_duplicate_normalized_email_does_not_leave_an_extra_tenant(): void
    {
        app(RegisterOwner::class)->create($this->input());
        $count = Tenant::query()->count();
        try {
            app(RegisterOwner::class)->create(array_replace($this->input(), ['email' => ' OWNER-REGISTRATION@EXAMPLE.TEST ']));
            $this->fail('Doppelte E-Mail wurde angenommen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.email', $exception->errors());
        }
        $this->assertSame($count, Tenant::query()->count());
    }

    public function test_price_is_snapshotted_from_admin_settings_and_is_not_replaced_later(): void
    {
        $admin = (new SuperAdmin)->forceFill(['name' => 'Price Test', 'email' => 'price-registration@example.test', 'password' => 'Synthetic-test-password']);
        $admin->save();
        $db = DB::connection('central');
        $priceId = $db->table('billing_settings_versions')->insertGetId(['gross_cents' => 250, 'tax_basis_points' => 1900, 'prenotification_days' => 2, 'created_by' => $admin->id, 'created_at' => now()]);
        $owner = app(RegisterOwner::class)->create($this->input());
        $db->table('billing_settings_versions')->insert(['gross_cents' => 500, 'tax_basis_points' => 1900, 'prenotification_days' => 2, 'created_by' => $admin->id, 'created_at' => now()]);
        $this->assertDatabaseHas('registration_requests', ['tenant_id' => $owner->tenant_id, 'gross_cents' => 250, 'billing_settings_version_id' => $priceId], 'central');
    }

    public function test_missing_smtp_is_reported_safely_and_registration_remains_unverified(): void
    {
        $owner = app(RegisterOwner::class)->create($this->input());
        DB::connection('central')->table('platform_smtp_settings')->delete();
        try {
            app(SendVerificationMail::class)->send($owner);
            $this->fail('Fehlender Absender wurde nicht erkannt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Dein Konto bleibt angelegt', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $owner->tenant_id, 'action' => 'registration.verification_mail_failed'], 'central');
    }

    public function test_registration_is_forbidden_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $this->get('/owner/register')->assertForbidden();
        $this->get('/')->assertDontSee('Chef-Zugang und erste Tankstelle registrieren');
        $this->expectException(HttpException::class);
        app(RegisterOwner::class)->create($this->input());
    }

    public function test_failed_smtp_keeps_account_and_allows_later_resend(): void
    {
        $this->mock(SendVerificationMail::class)->shouldReceive('send')->twice()->andThrow(new RuntimeException('Versand vorübergehend nicht verfügbar.'));
        Livewire::test(Register::class)->fillForm($this->input())->call('register')->assertHasNoFormErrors();
        $this->assertAuthenticated('web');
        $this->get('/owner/email-verification/prompt')->assertOk()->assertSee('Versand vorübergehend nicht verfügbar.');
        Livewire::test(VerifyEmailPrompt::class)->callAction('resendNotification')->assertHasErrors('verificationMail');
    }

    public function test_signed_confirmation_queues_once_and_rejects_wrong_owner_tampering_and_expiry(): void
    {
        $owner = app(RegisterOwner::class)->create($this->input());
        $url = Filament::getPanel('owner')->getVerifyEmailUrl($owner);
        $other = app(RegisterOwner::class)->create(array_replace($this->input(), ['email' => 'second@example.test']));
        $this->actingAs($other, 'web')->get($url)->assertForbidden();
        $this->app['session']->flush();
        $this->actingAs($owner, 'web')->get($url.'&tampered=1')->assertForbidden();
        $expired = URL::temporarySignedRoute('filament.owner.auth.email-verification.verify', now()->subMinute(), ['id' => $owner->id, 'hash' => sha1($owner->email)]);
        $this->get($expired)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->get($url)->assertRedirect();
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
        $this->get($url)->assertRedirect();
        Queue::assertPushed(ProvisionTenant::class, 1);
        $this->get('/owner/overview')->assertStatus(202);
    }

    public function test_registered_owner_can_be_provisioned_with_one_station_chef_and_stable_trial(): void
    {
        $directory = DB::connection('central')->selectOne('SELECT @@datadir AS path')->path;
        if (! str_contains(str_replace('\\', '/', $directory), '/StationDesk/.runtime/mysql-tests/')) {
            throw new RuntimeException('Temporäre MySQL-Testinstanz erforderlich.');
        }
        config(['database.connections.provisioner.username' => 'root', 'database.connections.provisioner.password' => '']);
        $owner = app(RegisterOwner::class)->create($this->input());
        $this->provisionedTenant = $owner->tenant;
        $this->actingAs($owner, 'web')->get(Filament::getPanel('owner')->getVerifyEmailUrl($owner))->assertRedirect();
        app(Provisioner::class)->provision($owner->tenant_id);
        $subscription = DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertEquals(1, $subscription->is_test_registration);
        $this->assertSame(30, (int) Carbon::parse($subscription->trial_started_at)->diffInDays($subscription->trial_ends_at));
        app(Provisioner::class)->provision($owner->tenant_id);
        $this->assertSame($subscription->trial_started_at, DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->value('trial_started_at'));
        app(TenantContext::class)->forOwner($owner->fresh(), function () use ($owner): void {
            $this->assertSame('Erste Teststation', Station::query()->sole()->name);
            $this->assertSame($owner->id, Employee::query()->sole()->owner_id);
            $this->assertSame(1, DB::connection('tenant')->table('employee_station_assignments')->count());
        });
        $this->get('/owner/overview')->assertOk()->assertSee('Erste Teststation');
        $this->get('/admin/provisioning')->assertRedirect('/admin/login');
    }

    public function test_verification_mail_uses_saved_smtp_and_persisted_recipient(): void
    {
        $owner = app(RegisterOwner::class)->create($this->input());
        $admin = (new SuperAdmin)->forceFill(['name' => 'SMTP Test', 'email' => 'smtp-registration@example.test', 'password' => 'Synthetic-test-password']);
        $admin->save();
        DB::connection('central')->table('platform_smtp_settings')->delete();
        DB::connection('central')->table('platform_smtp_settings')->insert(['revision' => 1, 'host' => 'smtp.example.test', 'port' => 587,
            'encryption' => 'starttls', 'username' => 'synthetic', 'password' => Crypt::encryptString('synthetic-smtp-secret'),
            'from_address' => 'platform@example.test', 'from_name' => 'StationDeck Test', 'updated_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now()]);
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $this->mock(MailManager::class)->shouldReceive('build')->once()->with(Mockery::on(fn ($config) => $config['require_tls'] && $config['host'] === 'smtp.example.test'))->andReturn($mailer);
        $owner->email = 'manipulated@example.test';
        app(SendVerificationMail::class)->send($owner);
        $message = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($this->input()['email'], $message->getTo()[0]->getAddress());
        $this->assertSame('platform@example.test', $message->getFrom()[0]->getAddress());
        $this->assertStringContainsString('/owner/email-verification/verify/', $message->getTextBody());
        $this->assertStringContainsString('signature=', $message->getTextBody());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $owner->tenant_id, 'action' => 'registration.verification_mail_accepted'], 'central');
    }
}
