<?php

namespace Tests\Feature;

use App\Filament\Owner\Auth\RequestPasswordReset;
use App\Filament\Owner\Auth\ResetPassword;
use App\Models\Owner;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Onboarding\SendPasswordResetMail;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Echte Broker-/Formularprüfungen auf isoliertem MySQL; alle Mails bleiben im Speicher oder werden gemockt. */
class PasswordRecoveryTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    /** Legt nur zentrale Testidentitäten an; keine physische Mandantendatenbank erforderlich. */
    private function owner(): Owner
    {
        $tenant = (new Tenant)->forceFill(['id' => (string) Str::uuid(), 'company_name' => 'Reset-Test',
            'tenancy_db_name' => 'sd_t_'.bin2hex(random_bytes(16)), 'tenancy_db_username' => 'sdu_'.bin2hex(random_bytes(14)),
            'tenancy_db_password' => 'synthetic-database-secret']);
        $tenant->save();

        return Owner::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_request_uses_owner_broker_and_normalizes_email(): void
    {
        $owner = $this->owner();
        $this->mock(SendPasswordResetMail::class)->shouldReceive('send')->once()
            ->with(Mockery::on(fn ($sentOwner) => $sentOwner->id === $owner->id), Mockery::on(fn ($token) => Password::broker('owners')->tokenExists($owner, $token)));
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => strtoupper($owner->email)])
            ->call('request')->assertHasNoFormErrors()->assertNotified('Anfrage verarbeitet');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email], 'central');
    }

    public function test_unknown_and_admin_only_addresses_do_not_send_owner_mail(): void
    {
        $admin = (new SuperAdmin)->forceFill(['name' => 'Reset Admin', 'email' => 'reset-admin@example.test', 'password' => 'Synthetic-test-password']);
        $admin->save();
        $this->mock(SendPasswordResetMail::class)->shouldNotReceive('send');
        foreach (['missing@example.test', $admin->email] as $email) {
            Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request')->assertNotified('Anfrage verarbeitet');
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email], 'central');
        }
    }

    public function test_reset_changes_password_rotates_remember_token_and_consumes_link(): void
    {
        $owner = $this->owner();
        $token = Password::broker('owners')->createToken($owner);
        $this->get(Filament::getPanel('owner')->getResetPasswordUrl($token, $owner))->assertOk();
        $form = Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $token])
            ->fillForm(['password' => 'New-secure-password-123', 'passwordConfirmation' => 'New-secure-password-123'])
            ->call('resetPassword')->assertHasNoFormErrors()->assertSet('password', '')->assertSet('passwordConfirmation', '');
        $this->assertTrue(Hash::check('New-secure-password-123', $owner->fresh()->password));
        $this->assertNotEmpty($owner->fresh()->remember_token);
        $this->assertFalse(Password::broker('owners')->tokenExists($owner, $token));
        $this->assertSame(Password::INVALID_TOKEN, Password::broker('owners')->reset(['email' => $owner->email, 'token' => $token, 'password' => 'Another-test-password-123', 'password_confirmation' => 'Another-test-password-123'], fn () => $this->fail('Token wurde zweimal akzeptiert.')));
    }

    public function test_expired_and_wrong_identity_tokens_cannot_change_password(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $token = Password::broker('owners')->createToken($owner);
        $this->assertFalse(Password::broker('owners')->tokenExists($other, $token));
        DB::connection('central')->table('password_reset_tokens')->where('email', $owner->email)->update(['created_at' => now()->subMinutes(config('auth.passwords.owners.expire') + 1)]);
        $this->assertFalse(Password::broker('owners')->tokenExists($owner, $token));
        $this->assertTrue(Hash::check('test-only-password', $owner->fresh()->password));
    }

    public function test_short_password_rejected_and_smtp_failure_does_not_expose_account(): void
    {
        $owner = $this->owner();
        $token = Password::broker('owners')->createToken($owner);
        Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $token])->fillForm(['password' => 'shortpass', 'passwordConfirmation' => 'shortpass'])
            ->call('resetPassword')->assertHasFormErrors(['password'])->assertSet('password', '');
        Password::broker('owners')->deleteToken($owner);
        $this->mock(SendPasswordResetMail::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic SMTP failure'));
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $owner->email])->call('request')->assertNotified('Anfrage verarbeitet');
    }

    public function test_reset_mail_has_matching_html_and_text_links_and_saved_sender(): void
    {
        $owner = $this->owner();
        $admin = (new SuperAdmin)->forceFill(['name' => 'SMTP Reset', 'email' => 'smtp-reset@example.test', 'password' => 'Synthetic-test-password']);
        $admin->save();
        DB::connection('central')->table('platform_smtp_settings')->delete();
        DB::connection('central')->table('platform_smtp_settings')->insert(['revision' => 1, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'synthetic', 'password' => Crypt::encryptString('synthetic-secret'), 'from_address' => 'platform@example.test', 'from_name' => 'StationDeck', 'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $this->mock(MailManager::class)->shouldReceive('build')->once()->with(Mockery::on(fn ($settings) => $settings['require_tls'] === true))->andReturn($mailer);
        app(SendPasswordResetMail::class)->send($owner, Password::broker('owners')->createToken($owner));
        $message = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('platform@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame($owner->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Neues Passwort festlegen', $message->getHtmlBody());
        $this->assertStringNotContainsString('Testzeitraum', $message->getHtmlBody());
        preg_match('/href="([^"]+)"/', $message->getHtmlBody(), $button);
        $this->assertStringContainsString(html_entity_decode($button[1]), $message->getTextBody());
        $this->assertDatabaseHas('audit_events', ['subject_id' => (string) $owner->id, 'action' => 'owner.password_reset_mail_accepted'], 'central');
    }
}
