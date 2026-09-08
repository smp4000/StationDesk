<?php

namespace Tests\Feature;

use App\Filament\Pages\PlatformSettings;
use App\Models\Owner;
use App\Models\SuperAdmin;
use App\Settings\PlatformSettingsStore;
use App\Settings\SendPlatformTestMail;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** MySQL-Prüfungen für Plattformzugriff, Versionen, Geheimnisse und Formularzustände. */
class PlatformSettingsTest extends TestCase
{
    /** Isolierte Migrationen und Transaktionen schützen lokale Pilotdaten. */
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Isolierte Testdatenbank erforderlich.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
        DB::connection('central')->beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    /** Rollt auch Versionshistorien und Audit zurück. */
    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    /** Erstellt eine ausschließlich im Testschema existierende Plattformidentität. */
    private function admin(bool $mfa = true): SuperAdmin
    {
        $admin = (new SuperAdmin)->forceFill(['name' => 'Settings Test', 'email' => 'settings@example.test', 'password' => 'Test-password-12345']);
        $admin->save();
        if ($mfa) {
            $admin->saveAppAuthenticationSecret('TESTSECRET');
        }
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_guests_and_owners_cannot_read_settings(): void
    {
        $this->get('/admin/platform-settings')->assertRedirect('/admin/login');
        $this->actingAs((new Owner)->forceFill(['id' => 987, 'email' => 'owner@example.test']), 'web');
        Livewire::test(PlatformSettings::class)->assertForbidden();
    }

    public function test_admin_without_totp_cannot_read_settings(): void
    {
        $this->admin(false);
        Livewire::test(PlatformSettings::class)->assertForbidden();
    }

    public function test_billing_creates_versions_and_rejects_stale_forms(): void
    {
        $this->admin();
        $store = app(PlatformSettingsStore::class);
        $this->assertSame('1,00', $store->read('billing')['data']['gross_amount']);
        $store->save('billing', ['gross_amount' => '1,00', 'prenotification_days' => 2], 0);
        $first = DB::connection('central')->table('billing_settings_versions')->latest('id')->first();
        $store->save('billing', ['gross_amount' => '2.50', 'prenotification_days' => 3], $first->id);
        $this->assertSame(100, DB::connection('central')->table('billing_settings_versions')->where('id', $first->id)->value('gross_cents'));
        $this->assertSame(250, DB::connection('central')->table('billing_settings_versions')->latest('id')->value('gross_cents'));
        $this->expectException(ValidationException::class);
        $store->save('billing', ['gross_amount' => '3,00', 'prenotification_days' => 2], $first->id);
    }

    public function test_smtp_password_is_encrypted_hidden_and_preserved(): void
    {
        $this->admin();
        $store = app(PlatformSettingsStore::class);
        $input = ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'test',
            'password' => 'Secret-for-tests', 'from_address' => 'mail@example.test', 'from_name' => 'Test'];
        $store->save('smtp', $input, 0);
        $ciphertext = DB::connection('central')->table('platform_smtp_settings')->value('password');
        $this->assertNotSame($input['password'], $ciphertext);
        $this->assertSame($input['password'], Crypt::decryptString($ciphertext));
        $this->assertSame('', $store->read('smtp')['data']['password']);
        Livewire::test(PlatformSettings::class)->assertDontSee('Secret-for-tests')->assertSet('data.smtp.password', '');
        $store->save('smtp', array_replace($input, ['password' => '', 'port' => 465, 'encryption' => 'smtps']), 1);
        $this->assertSame($ciphertext, DB::connection('central')->table('platform_smtp_settings')->value('password'));
        $this->assertSame(1, DB::connection('central')->table('platform_smtp_settings')->count());
        $this->assertStringNotContainsString('Secret-for-tests', json_encode(DB::connection('central')->table('audit_events')->get()));
    }

    public function test_creditor_iban_is_encrypted_and_history_retains_old_profile(): void
    {
        $this->admin();
        $store = app(PlatformSettingsStore::class);
        $input = ['company_name' => 'Test', 'street' => 'Testweg 1', 'postal_code' => '36037', 'city' => 'Fulda',
            'country_code' => 'DE', 'creditor_identifier' => 'DE98ZZZ09999999999', 'account_holder' => 'Test',
            'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX'];
        $store->save('creditor', $input, 0);
        $first = DB::connection('central')->table('creditor_profile_versions')->latest('id')->first();
        $this->assertSame($input['iban'], Crypt::decryptString($first->iban));
        $this->assertSame('', $store->read('creditor')['data']['iban']);
        $store->save('creditor', array_replace($input, ['company_name' => 'Geändert', 'iban' => '']), $first->id);
        $this->assertSame('Test', DB::connection('central')->table('creditor_profile_versions')->where('id', $first->id)->value('company_name'));
        $this->assertSame($first->iban, DB::connection('central')->table('creditor_profile_versions')->latest('id')->value('iban'));
    }

    public function test_page_reports_invalid_fields_and_reloads_saved_values(): void
    {
        $this->admin();
        Livewire::test(PlatformSettings::class)
            ->set('data.billing.gross_amount', '0,00')->call('save', 'billing')->assertHasErrors('data.billing.gross_amount')
            ->set('data.billing.gross_amount', '1,25')->set('data.billing.prenotification_days', 0)
            ->call('save', 'billing')->assertHasErrors('data.billing.prenotification_days')
            ->set('data.billing.prenotification_days', 2)->call('save', 'billing')->assertHasNoErrors()
            ->set('data.billing.gross_amount', '9,99')->call('reloadGroup', 'billing')->assertSet('data.billing.gross_amount', '1,25');
    }

    public function test_fints_requires_https(): void
    {
        $this->admin();
        Livewire::test(PlatformSettings::class)
            ->set('data.fints.bank_code', '12345678')->set('data.fints.product_id', 'test-product')
            ->set('data.fints.endpoint', 'http://bank.example.test/fints')->call('save', 'fints')->assertHasErrors('data.fints.endpoint')
            ->set('data.fints.endpoint', 'https://bank.example.test/fints')->call('save', 'fints')->assertHasNoErrors();
        $this->assertSame(1, DB::connection('central')->table('platform_fints_settings')->count());
    }

    public function test_invalid_group_is_rejected(): void
    {
        $this->admin();
        Livewire::test(PlatformSettings::class)->call('save', 'super_admins')->assertNotFound();
    }

    /** Ausschließlich fiktive SMTP-Werte, die Tests ersetzen jeden tatsächlichen Transport. */
    private function smtpFixture(): void
    {
        app(PlatformSettingsStore::class)->save('smtp', [
            'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls',
            'username' => 'test', 'password' => 'Synthetic-secret',
            'from_address' => 'sender@example.test', 'from_name' => 'Testsender',
        ], 0);
    }

    public function test_testmail_uses_saved_settings_and_rejects_immediate_repeat(): void
    {
        $this->admin();
        $this->smtpFixture();
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->withArgs(function (array $config): bool {
            return $config['host'] === 'smtp.example.test' && $config['password'] === 'Synthetic-secret'
                && $config['scheme'] === 'smtp' && $config['require_tls'] === true && $config['timeout'] === 15;
        })->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);
        $page = Livewire::test(PlatformSettings::class)->set('data.smtp.host', 'unsaved.example.test')
            ->set('testRecipient', 'recipient@example.test')->call('sendTestMail')->assertHasNoErrors();
        $messages = $mailer->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
        $page->call('sendTestMail')->assertHasErrors('testRecipient');
        $this->assertSame(1, DB::connection('central')->table('audit_events')->where('action', 'platform_smtp.test_accepted')->count());
    }

    public function test_testmail_requires_saved_settings_and_valid_recipient(): void
    {
        $this->admin();
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $manager);
        Livewire::test(PlatformSettings::class)->set('testRecipient', 'invalid')->call('sendTestMail')->assertHasErrors('testRecipient')
            ->set('testRecipient', 'recipient@example.test')->call('sendTestMail')->assertHasErrors('testRecipient');
    }

    public function test_smtp_failure_does_not_expose_transport_secrets(): void
    {
        $this->admin();
        $this->smtpFixture();
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andThrow(new RuntimeException('Synthetic-secret SMTP debug'));
        $this->app->instance(MailManager::class, $manager);
        try {
            app(SendPlatformTestMail::class)->send('recipient@example.test');
            $this->fail('Fehler muss gemeldet werden.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('Synthetic-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(1, DB::connection('central')->table('audit_events')->where('action', 'platform_smtp.test_failed')->count());
        $this->assertStringNotContainsString('Synthetic-secret', json_encode(DB::connection('central')->table('audit_events')->get()));
    }

    public function test_testmail_rechecks_admin_after_mount(): void
    {
        $this->admin();
        $page = Livewire::test(PlatformSettings::class)->set('testRecipient', 'recipient@example.test');
        auth('admin')->logout();
        $page->call('sendTestMail')->assertForbidden();
    }

    public function test_write_action_rechecks_authentication_after_mount(): void
    {
        $this->admin();
        $page = Livewire::test(PlatformSettings::class);
        auth('admin')->logout();
        $page->call('save', 'billing')->assertForbidden();
        $this->assertSame(0, DB::connection('central')->table('billing_settings_versions')->count());
    }

    public function test_invalid_iban_and_array_input_are_field_errors(): void
    {
        $this->admin();
        $page = Livewire::test(PlatformSettings::class);
        foreach (['company_name' => 'Test', 'street' => 'Weg 1', 'postal_code' => '36037', 'city' => 'Fulda',
            'creditor_identifier' => 'DE98ZZZ09999999999', 'account_holder' => 'Test'] as $key => $value) {
            $page->set('data.creditor.'.$key, $value);
        }
        $page->set('data.creditor.iban', 'DE89370400440532013001')->call('save', 'creditor')->assertHasErrors('data.creditor.iban')
            ->set('data.creditor.iban', ['malformed'])->call('save', 'creditor')->assertHasErrors('data.creditor.iban');
        $this->assertSame(0, DB::connection('central')->table('creditor_profile_versions')->count());
    }
}
