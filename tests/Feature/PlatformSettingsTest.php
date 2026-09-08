<?php

namespace Tests\Feature;

use App\Filament\Pages\BankDirectoryImport;
use App\Filament\Pages\PlatformSettings;
use App\Models\Owner;
use App\Models\SuperAdmin;
use App\Settings\BankDirectory;
use App\Settings\FintsReadOnlyAdapter;
use App\Settings\FintsReadOnlyTest;
use App\Settings\PlatformSettingsStore;
use App\Settings\SendPlatformTestMail;
use Fhp\Action\GetSEPAAccounts;
use Fhp\FinTs;
use Fhp\Model\SEPAAccount;
use Fhp\Model\TanMode;
use Fhp\Protocol\DialogInitialization;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_can_upload_bank_csv_with_audit_and_idempotent_repeat(): void
    {
        $admin = $this->admin();
        $path = $this->bankCsv();
        try {
            $contents = file_get_contents($path);
            $page = Livewire::test(BankDirectoryImport::class)->set('validFrom', now()->subDay()->toDateString())
                ->set('validUntil', now()->addDay()->toDateString())
                ->set('csvFile', UploadedFile::fake()->createWithContent('bundesbank.csv', $contents))
                ->call('importCsv')->assertHasNoErrors()->assertSet('csvFile', null)->assertSee('Wird verwendet');
            $page->set('csvFile', UploadedFile::fake()->createWithContent('bundesbank.csv', $contents))->call('importCsv')->assertHasNoErrors();
            $this->assertSame(1, DB::connection('central')->table('bank_directory_imports')->count());
            $this->assertSame((string) $admin->id, DB::connection('central')->table('audit_events')->where('action', 'bank_directory.imported')->value('actor_id'));
        } finally {
            unlink($path);
        }
    }

    public function test_admin_csv_upload_rejects_bad_content_and_invalid_dates(): void
    {
        $this->admin();
        Livewire::test(BankDirectoryImport::class)->set('validFrom', now()->toDateString())->set('validUntil', now()->subDay()->toDateString())
            ->set('csvFile', UploadedFile::fake()->createWithContent('broken.csv', 'wrong;header'))
            ->call('importCsv')->assertHasErrors('validUntil')
            ->set('validUntil', now()->addDay()->toDateString())->call('importCsv')->assertHasErrors('csvFile')->assertSet('csvFile', null);
        $this->assertSame(0, DB::connection('central')->table('bank_directory_imports')->count());
    }

    public function test_bank_csv_page_requires_admin_mfa_and_rechecks_write_access(): void
    {
        $this->get('/admin/bank-directory-import')->assertRedirect('/admin/login');
        $admin = $this->admin(false);
        Livewire::test(BankDirectoryImport::class)->assertForbidden();
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $page = Livewire::test(BankDirectoryImport::class);
        auth('admin')->logout();
        $page->call('importCsv')->assertForbidden();
    }

    /** Synthetische öffentliche Bankdaten einschließlich Umlauten, führenden Nullen und leerer PAN. */
    private function bankCsv(string $change = 'U', bool $invalid = false): string
    {
        $header = 'Bankleitzahl;Merkmal;Bezeichnung;PLZ;Ort;Kurzbezeichnung;PAN;BIC;Prüfzifferberechnungsmethode;Datensatznummer;Änderungskennzeichen;Bankleitzahllöschung;Nachfolge-Bankleitzahl';
        $body = '37040044;1;Testbank Köln;01067;Köln;Testbank;;COBADEFFXXX;00;000001;'.$change.';0;00000000';
        $path = tempnam(sys_get_temp_dir(), 'sd-banks-');
        file_put_contents($path, mb_convert_encoding($header."\n".$body."\n".($invalid ? 'broken;row' : ''), 'Windows-1252', 'UTF-8'));

        return $path;
    }

    public function test_bank_csv_is_idempotent_and_preserves_leading_zeros_and_encoding(): void
    {
        $this->admin();
        $path = $this->bankCsv();
        try {
            $directory = app(BankDirectory::class);
            $from = now()->subDay()->toDateString();
            $until = now()->addDay()->toDateString();
            $first = $directory->import($path, $from, $until);
            $this->assertSame($first, $directory->import($path, $from, $until));
            $this->assertSame('01067', DB::connection('central')->table('bank_directory_entries')->value('postal_code'));
            $this->assertSame('Testbank Köln', $directory->find('37040044')['name']);
            $this->assertSame(1, DB::connection('central')->table('bank_directory_entries')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_invalid_bank_csv_does_not_create_partial_import(): void
    {
        $path = $this->bankCsv(invalid: true);
        try {
            app(BankDirectory::class)->import($path, now()->toDateString(), now()->addDay()->toDateString());
            $this->fail('Defekte CSV muss abgewiesen werden.');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::connection('central')->table('bank_directory_imports')->count());
            $this->assertSame(0, DB::connection('central')->table('bank_directory_entries')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_standard_iban_proposal_and_existing_iban_use_bank_directory(): void
    {
        $this->admin();
        $path = $this->bankCsv();
        try {
            app(BankDirectory::class)->import($path, now()->subDay()->toDateString(), now()->addDay()->toDateString());
            Livewire::test(PlatformSettings::class)->set('bankCode', '37040044')->set('accountNumber', '532013000')
                ->call('proposeIban')->assertHasNoErrors()->assertSet('ibanResult.iban', 'DE89370400440532013000')
                ->assertSet('ibanResult.kind', 'proposal')->assertSet('accountNumber', '')
                ->call('applyIban')->assertDispatched('close-modal', id: 'iban-help')->assertSet('data.creditor.iban', 'DE89370400440532013000')->assertSet('data.creditor.bic', 'COBADEFFXXX')
                ->set('ibanCheck', 'de89 3704 0044 0532 0130 00')->call('checkIban')->assertSet('ibanResult.kind', 'checked')
                ->set('ibanCheck', 'DE89370400440532013001')->call('checkIban')->assertHasErrors('ibanCheck');
            $this->assertSame(0, DB::connection('central')->table('creditor_profile_versions')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_deleted_and_expired_banks_cannot_generate_iban(): void
    {
        $this->admin();
        $path = $this->bankCsv('D');
        try {
            app(BankDirectory::class)->import($path, now()->subDay()->toDateString(), now()->addDay()->toDateString());
            Livewire::test(PlatformSettings::class)->set('bankCode', '37040044')->set('accountNumber', '532013000')->call('proposeIban')->assertHasErrors('bankCode');
            DB::connection('central')->table('bank_directory_imports')->update(['valid_until' => now()->subDay()->toDateString()]);
            Livewire::test(PlatformSettings::class)->set('bankCode', '37040044')->set('accountNumber', '532013000')->call('proposeIban')->assertHasErrors('bankCode');
        } finally {
            unlink($path);
        }
    }

    public function test_iban_helper_rechecks_admin_authorization(): void
    {
        $this->admin();
        $page = Livewire::test(PlatformSettings::class);
        auth('admin')->logout();
        $page->call('proposeIban')->assertForbidden();
    }

    /** Freigegebener Endpunkt, jedoch stets durch einen simulierten Bankadapter ohne Netzwerk ersetzt. */
    private function bankFixture(): void
    {
        app(PlatformSettingsStore::class)->save('fints', ['bank_name' => 'Testbank', 'bank_code' => '12345678',
            'endpoint' => 'https://fints2.atruvia.de/cgi-bin/hbciservlet', 'product_id' => 'test-product'], 0);
    }

    public function test_bank_credentials_are_hidden_encrypted_and_deleted_after_completion(): void
    {
        $admin = $this->admin();
        $this->bankFixture();
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class);
        $adapter->shouldReceive('advance')->once()->withArgs(fn (array $state, string $choice): bool => $state['phase'] === 'start' && $state['pin'] === 'Private-test-PIN')
            ->andReturnUsing(fn (array $state): array => array_replace($state, ['phase' => 'mode', 'options' => ['900' => 'SecureGo plus'], 'client' => 'private-dialog']));
        $adapter->shouldReceive('advance')->once()->withArgs(fn (array $state, string $choice): bool => $state['phase'] === 'mode' && $choice === '900')
            ->andReturn(['phase' => 'done', 'accounts' => ['•••• 1234']]);
        $this->app->instance(FintsReadOnlyAdapter::class, $adapter);
        $page = Livewire::test(PlatformSettings::class)->set('bankLogin', 'test-netkey')->set('bankPin', 'Private-test-PIN')->call('startBankTest')
            ->assertHasNoErrors()->assertSet('bankPin', '')->assertSet('bankLogin', '')->assertDontSee('Private-test-PIN')->assertDontSee('private-dialog');
        $key = 'fints-read-test:'.$admin->id.':'.hash('sha256', session()->getId());
        $ciphertext = Cache::store('database')->get($key);
        $this->assertStringNotContainsString('Private-test-PIN', $ciphertext);
        $this->assertSame('Private-test-PIN', Crypt::decrypt($ciphertext)['pin']);
        $page->set('bankChoice', '900')->call('continueBankTest')->assertHasNoErrors()->assertSee('Kontenabfrage erfolgreich');
        $this->assertNull(Cache::store('database')->get($key));
        $this->assertStringNotContainsString('Private-test-PIN', json_encode(DB::connection('central')->table('audit_events')->get()));
    }

    public function test_bank_wait_interval_replay_and_session_binding(): void
    {
        $this->admin();
        $this->bankFixture();
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class);
        $adapter->shouldReceive('advance')->once()->andReturnUsing(fn (array $state): array => array_replace($state, [
            'phase' => 'waiting', 'checks' => 0, 'max_checks' => 2, 'next_check' => time() + 30, 'challenge' => 'Bestätigen',
        ]));
        $this->app->instance(FintsReadOnlyAdapter::class, $adapter);
        $service = app(FintsReadOnlyTest::class);
        $state = $service->start('test', 'pin');
        foreach (['wrong-token', $state['token']] as $token) {
            try {
                $service->advance($token);
                $this->fail('Ungültiger oder zu früher Dialogschritt muss abgewiesen werden.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $sessionId = session()->getId();
        session()->setId(str_repeat('b', 40));
        try {
            $service->advance($state['token']);
            $this->fail('Andere Sitzung darf den Dialog nicht fortsetzen.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        } finally {
            session()->setId($sessionId);
            $service->cancel();
        }
    }

    public function test_bank_errors_do_not_expose_credentials_and_clear_state(): void
    {
        $this->admin();
        $this->bankFixture();
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class);
        $adapter->shouldReceive('advance')->once()->andThrow(new RuntimeException('Private-test-PIN protocol trace'));
        $this->app->instance(FintsReadOnlyAdapter::class, $adapter);
        Livewire::test(PlatformSettings::class)->set('bankLogin', 'test')->set('bankPin', 'Private-test-PIN')->call('startBankTest')
            ->assertHasErrors('bankTest')->assertSet('bankPin', '')->assertDontSee('Private-test-PIN');
    }

    public function test_bank_adapter_filters_out_non_phone_methods(): void
    {
        $phone = Mockery::mock(TanMode::class);
        $phone->shouldReceive('isDecoupled')->andReturn(true);
        $phone->shouldReceive('isProzessvariante2')->andReturn(true);
        $phone->shouldReceive('getId')->andReturn(900);
        $phone->shouldReceive('getName')->andReturn('SecureGo plus');
        $chip = Mockery::mock(TanMode::class);
        $chip->shouldReceive('isDecoupled')->andReturn(false);
        $client = Mockery::mock(FinTs::class);
        $client->shouldReceive('getTanModes')->once()->andReturn([900 => $phone, 901 => $chip]);
        $client->shouldReceive('persist')->once()->andReturn('private-state');
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $adapter->shouldReceive('client')->once()->andReturn($client);
        $state = $adapter->advance(['phase' => 'start']);
        $this->assertSame([900 => 'SecureGo plus'], $state['options']);
        $this->assertSame('mode', $state['phase']);
    }

    public function test_bank_adapter_repersists_after_unconfirmed_phone_check(): void
    {
        $mode = Mockery::mock(TanMode::class);
        $mode->shouldReceive('getPeriodicDecoupledCheckDelaySeconds')->andReturn(5);
        $client = Mockery::mock(FinTs::class);
        $client->shouldReceive('checkDecoupledSubmission')->once()->andReturn(false);
        $client->shouldReceive('getSelectedTanMode')->andReturn($mode);
        $client->shouldReceive('persist')->once()->andReturn('updated-private-state');
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $adapter->shouldReceive('client')->once()->andReturn($client);
        $state = $adapter->advance(['phase' => 'waiting', 'step' => 'accounts', 'checks' => 0, 'action' => serialize(GetSEPAAccounts::create())]);
        $this->assertSame(1, $state['checks']);
        $this->assertSame('updated-private-state', $state['client']);
        $this->assertGreaterThanOrEqual(time() + 4, $state['next_check']);
    }

    public function test_bank_adapter_reads_only_accounts_and_masks_them_when_bank_needs_no_challenge(): void
    {
        $mode = Mockery::mock(TanMode::class);
        $mode->shouldReceive('isDecoupled')->andReturn(true);
        $mode->shouldReceive('needsTanMedium')->andReturn(false);
        $login = Mockery::mock(DialogInitialization::class);
        $login->shouldReceive('needsTan')->once()->andReturn(false);
        $accounts = Mockery::mock(GetSEPAAccounts::class);
        $accounts->shouldReceive('needsTan')->once()->andReturn(false);
        $accounts->shouldReceive('getAccounts')->once()->andReturn([(new SEPAAccount)->setIban('DE89370400440532013000')]);
        $client = Mockery::mock(FinTs::class);
        $client->shouldReceive('getTanModes')->andReturn([900 => $mode]);
        $client->shouldReceive('selectTanMode')->once()->with($mode);
        $client->shouldReceive('login')->once()->andReturn($login);
        $client->shouldReceive('execute')->once()->with($accounts);
        $client->shouldReceive('close')->once();
        $adapter = Mockery::mock(FintsReadOnlyAdapter::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $adapter->shouldReceive('client')->once()->andReturn($client);
        $adapter->shouldReceive('accountsAction')->once()->andReturn($accounts);
        $result = $adapter->advance(['phase' => 'mode', 'options' => [900 => 'SecureGo plus']], '900');
        $this->assertSame(['phase' => 'done', 'accounts' => ['•••• 3000']], $result);
    }

    public function test_fints_connection_reports_http_without_claiming_bank_login(): void
    {
        $this->admin();
        app(PlatformSettingsStore::class)->save('fints', ['bank_name' => 'Testbank', 'bank_code' => '12345678',
            'endpoint' => 'https://fints2.atruvia.de/cgi-bin/hbciservlet', 'product_id' => 'test-product'], 0);
        Http::preventStrayRequests();
        Http::fake(['https://fints2.atruvia.de/*' => Http::response('', 405)]);
        Livewire::test(PlatformSettings::class)->call('testFintsConnection')->assertHasNoErrors()
            ->assertSee('HTTP-Status 405')->assertSee('noch nicht bestätigt');
        Http::assertSent(fn ($request): bool => $request->method() === 'HEAD' && $request->body() === '');
        Http::assertSentCount(1);
    }

    public function test_fints_connection_rejects_internal_endpoint(): void
    {
        $this->admin();
        app(PlatformSettingsStore::class)->save('fints', ['bank_name' => 'Testbank', 'bank_code' => '12345678',
            'endpoint' => 'https://127.0.0.1/private', 'product_id' => 'test-product'], 0);
        Http::fake();
        Livewire::test(PlatformSettings::class)->call('testFintsConnection')->assertHasErrors('fintsTest');
        Http::assertNothingSent();
    }

    public function test_fints_connection_requires_settings_and_current_admin(): void
    {
        $this->admin();
        Http::fake();
        $page = Livewire::test(PlatformSettings::class)->call('testFintsConnection')->assertHasErrors('fintsTest');
        auth('admin')->logout();
        $page->call('testFintsConnection')->assertForbidden();
        Http::assertNothingSent();
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
