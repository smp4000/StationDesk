<?php

namespace Tests\Feature;

use App\Billing\ManageSubscriptions;
use App\Filament\Owner\Pages\Overview;
use App\Filament\Owner\Pages\Settings;
use App\Models\Owner;
use App\Models\Tenant;
use App\Tenancy\Provisioner;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Mandantentrennung, lokale Kündigung und Bestätigungsdialog auf isoliertem MySQL. */
class SubscriptionManagementTest extends TestCase
{
    private ?Tenant $provisionedTenant = null;

    /** Zentraler Rollback hält alle Vertragsfixtures von der lokalen Pilotregistrierung getrennt. */
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Isolierte Testdatenbank erforderlich.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
        DB::connection('central')->beginTransaction();
        $this->travelTo(Carbon::parse('2026-09-08 12:00:00', 'UTC'));
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->withoutVite();
    }

    /** DDL-Bereinigung über eine andere Verbindung verhindert ein implizites Commit zentraler Testdaten. */
    protected function tearDown(): void
    {
        tenancy()->end();
        if ($this->provisionedTenant) {
            DB::connection('provisioner')->statement('DROP DATABASE IF EXISTS '.$this->provisionedTenant->tenancy_db_name);
            DB::connection('provisioner')->statement("DROP USER IF EXISTS '".$this->provisionedTenant->tenancy_db_username."'@'%'");
        }
        DB::connection('central')->rollBack();
        $this->travelBack();
        parent::tearDown();
    }

    private function owner(): Owner
    {
        $tenant = (new Tenant)->forceFill(['id' => (string) Str::uuid(), 'company_name' => 'Abotest', 'provisioning_status' => 'ready',
            'tenancy_db_name' => 'sd_t_'.bin2hex(random_bytes(16)), 'tenancy_db_username' => 'sdu_'.bin2hex(random_bytes(14)), 'tenancy_db_password' => bin2hex(random_bytes(32))]);
        $tenant->save();

        return Owner::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function subscription(Owner $owner, bool $test = true): int
    {
        return DB::connection('central')->table('subscriptions')->insertGetId(['tenant_id' => $owner->tenant_id, 'station_id' => (string) Str::uuid(),
            'status' => 'trial', 'gross_cents' => 100, 'tax_basis_points' => 1900, 'currency' => 'EUR',
            'trial_started_at' => '2026-09-01 12:00:00', 'trial_ends_at' => '2026-10-01 12:00:00', 'billing_anchor_at' => '2026-10-01 12:00:00',
            'is_test_registration' => $test, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_trial_cancellation_ends_at_trial_end_is_idempotent_and_does_not_renew(): void
    {
        $owner = $this->owner();
        $id = $this->subscription($owner);
        $this->actingAs($owner, 'web');
        $service = app(ManageSubscriptions::class);
        $quote = $service->quote($id);
        $this->assertSame('2026-10-01 12:00:00', $quote['cancellation_end']);
        $service->cancel($id, $quote['cancellation_end']);
        $this->travel(2)->days();
        $service->cancel($id, $quote['cancellation_end']);
        $this->assertSame(1, DB::connection('central')->table('subscription_cancellations')->where('subscription_id', $id)->count());
        $this->assertSame(1, DB::connection('central')->table('audit_events')->where('action', 'subscription.cancellation_requested')->where('subject_id', $id)->count());
        $this->assertSame('Kündigung vorgemerkt', $service->all()[0]['status_label']);
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        $view = $service->all()[0];
        $this->assertTrue($view['ended']);
        $this->assertNull($view['period_end']);
        $this->assertSame('2026-10-01 12:00:00', $view['ends_at']);
    }

    public function test_post_trial_monthly_cancellation_and_stale_confirmation(): void
    {
        $owner = $this->owner();
        $id = $this->subscription($owner);
        $this->actingAs($owner, 'web');
        $service = app(ManageSubscriptions::class);
        $quote = $service->quote($id);
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        try {
            $service->cancel($id, $quote['cancellation_end']);
            $this->fail('Veralteter Endtermin wurde übernommen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancellation', $exception->errors());
        }
        $this->assertNull(DB::connection('central')->table('subscriptions')->where('id', $id)->value('cancelled_at'));
        $quote = $service->quote($id);
        $this->assertSame('2026-11-01 12:00:00', $quote['cancellation_end']);
        $service->cancel($id, $quote['cancellation_end']);
        $this->assertDatabaseHas('subscription_cancellations', ['subscription_id' => $id, 'effective_at' => '2026-11-01 12:00:00'], 'central');
    }

    public function test_foreign_subscription_is_not_listed_or_cancellable(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $ownId = $this->subscription($owner);
        $foreignId = $this->subscription($other);
        $this->actingAs($owner, 'web');
        $this->assertSame([$ownId], array_column(app(ManageSubscriptions::class)->all(), 'id'));
        try {
            app(ManageSubscriptions::class)->cancel($foreignId, '2026-10-01 12:00:00');
            $this->fail('Fremdes Abo wurde gekündigt.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('subscription_cancellations', ['subscription_id' => $foreignId], 'central');
        try {
            app(ManageSubscriptions::class)->reactivate($foreignId, 1);
            $this->fail('Fremdes Abo wurde reaktiviert.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_withdrawal_and_later_reactivation_preserve_rhythm_price_and_complete_history(): void
    {
        $owner = $this->owner();
        $id = $this->subscription($owner);
        $this->actingAs($owner, 'web');
        $service = app(ManageSubscriptions::class);
        $first = $service->cancel($id, $service->quote($id)['cancellation_end']);
        $withdrawn = $service->reactivate($id, (int) $first['cancellation_id']);
        $this->assertTrue($withdrawn['in_trial']);
        $this->assertNull($withdrawn['cancelled_at']);
        $service->reactivate($id, (int) $first['cancellation_id']);
        $this->assertSame(1, DB::connection('central')->table('subscription_reactivations')->where('cancellation_id', $first['cancellation_id'])->count());
        try {
            $service->cancel($id, $first['cancellation_end']);
            $this->fail('Alter Kündigungsdialog wurde nach der Rücknahme angenommen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancellation', $exception->errors());
        }
        $second = $service->cancel($id, $withdrawn['cancellation_end'], (int) $withdrawn['cancellation_id']);
        try {
            $service->reactivate($id, (int) $first['cancellation_id']);
            $this->fail('Alte Rücknahme hat eine neue Kündigung aufgehoben.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reactivation', $exception->errors());
        }
        $this->travelTo(Carbon::parse('2027-01-17 15:00:00', 'UTC'));
        $this->assertTrue($service->quote($id)['ended']);
        $resumed = $service->reactivate($id, (int) $second['cancellation_id']);
        $this->assertFalse($resumed['ended']);
        $this->assertFalse($resumed['in_trial']);
        $this->assertSame('2027-01-01 12:00:00', $resumed['period_start']);
        $this->assertSame('2027-02-01 12:00:00', $resumed['period_end']);
        $this->assertDatabaseHas('subscriptions', ['id' => $id, 'gross_cents' => 100, 'billing_anchor_at' => '2026-10-01 12:00:00', 'trial_ends_at' => '2026-10-01 12:00:00', 'cancelled_at' => null, 'ends_at' => null], 'central');
        $this->assertSame(2, DB::connection('central')->table('subscription_cancellations')->where('subscription_id', $id)->count());
        $this->assertDatabaseHas('subscription_reactivations', ['cancellation_id' => $first['cancellation_id'], 'was_ended' => false, 'reactivated_by' => $owner->id], 'central');
        $this->assertDatabaseHas('subscription_reactivations', ['cancellation_id' => $second['cancellation_id'], 'was_ended' => true, 'reactivated_by' => $owner->id], 'central');
        foreach (['subscription.reactivated', 'subscription.cancellation_withdrawn'] as $action) {
            $this->assertDatabaseHas('audit_events', ['subject_id' => (string) $id, 'action' => $action], 'central');
        }
    }

    public function test_real_contract_and_production_environment_cannot_be_cancelled(): void
    {
        $owner = $this->owner();
        $real = $this->subscription($owner, false);
        $test = $this->subscription($owner);
        $this->actingAs($owner, 'web');
        foreach ([$real, $test] as $id) {
            if ($id === $test) {
                $this->app['env'] = 'production';
            }
            try {
                app(ManageSubscriptions::class)->cancel($id, '2026-10-01 12:00:00');
                $this->fail('Nicht freigegebene Kündigung angenommen.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            try {
                app(ManageSubscriptions::class)->reactivate($id, 1);
                $this->fail('Nicht freigegebene Reaktivierung angenommen.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_unverified_owner_and_guest_have_no_access(): void
    {
        $this->get('/owner/subscriptions')->assertRedirect('/owner/login');
        $owner = $this->owner();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner, 'web');
        $this->expectException(HttpException::class);
        app(ManageSubscriptions::class)->all();
    }

    public function test_modal_flow_only_cancels_after_explicit_confirmation(): void
    {
        $directory = DB::connection('central')->selectOne('SELECT @@datadir AS path')->path;
        if (! str_contains(str_replace('\\', '/', $directory), '/StationDesk/.runtime/mysql-tests/')) {
            throw new RuntimeException('Temporäre MySQL-Testinstanz erforderlich.');
        }
        config(['database.connections.provisioner.username' => 'root', 'database.connections.provisioner.password' => '']);
        $owner = $this->owner();
        $tenant = $owner->tenant;
        $tenant->forceFill(['provisioning_status' => 'pending'])->save();
        $this->provisionedTenant = $tenant;
        DB::connection('central')->table('registration_requests')->insert(['tenant_id' => $tenant->id,
            'station_id' => (string) Str::uuid(), 'employee_id' => (string) Str::uuid(), 'station_name' => 'Abo-Teststation',
            'station_street' => 'Testweg 1', 'station_postal_code' => '36037', 'station_city' => 'Fulda', 'station_country_code' => 'DE',
            'gross_cents' => 100, 'tax_basis_points' => 1900, 'is_test_registration' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(Provisioner::class)->provision($tenant->id);
        $this->actingAs($owner, 'web');
        $id = DB::connection('central')->table('subscriptions')->where('tenant_id', $tenant->id)->value('id');
        $html = $this->get('/owner/settings')->assertOk()->assertSee('Kunden-Einstellungen')->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = collect($matches[1])->map(fn ($encoded) => html_entity_decode($encoded, ENT_QUOTES))
            ->first(fn ($json) => array_key_exists('cancellation', json_decode($json, true)['data']));
        $this->assertNotNull($snapshot);
        $this->assertFalse(tenancy()->initialized);
        // Echte getrennte HTTP-Anfragen: die persistente Middleware endet vor der jeweiligen Aktion.
        $prepared = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'prepareCancellation', 'params' => [$id]]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertStringContainsString('Abo-Teststation', $prepared->json('components.0.effects.html'));
        $this->assertNull(DB::connection('central')->table('subscriptions')->where('id', $id)->value('cancelled_at'));
        $this->assertFalse(tenancy()->initialized);
        $confirmed = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $prepared->json('components.0.snapshot'), 'updates' => [],
            'calls' => [['path' => '', 'method' => 'confirmCancellation', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertFalse(tenancy()->initialized);
        $this->assertDatabaseHas('subscription_cancellations', ['subscription_id' => $id, 'requested_by' => $owner->id], 'central');
        $preparedReactivation = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $confirmed->json('components.0.snapshot'), 'updates' => [],
            'calls' => [['path' => '', 'method' => 'prepareReactivation', 'params' => [$id]]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertNotNull(DB::connection('central')->table('subscriptions')->where('id', $id)->value('cancelled_at'));
        $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $preparedReactivation->json('components.0.snapshot'), 'updates' => [],
            'calls' => [['path' => '', 'method' => 'confirmReactivation', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertNull(DB::connection('central')->table('subscriptions')->where('id', $id)->value('cancelled_at'));
        $this->assertFalse(tenancy()->initialized);
        Livewire::test(Settings::class)->set('colorScheme', 'oil')->call('saveAppearance')
            ->assertRedirect(Settings::getUrl().'?tab=appearance');
        $this->get('/owner/settings?tab=appearance')->assertOk()->assertSee('--sd-sidebar: #681f79', false);
        $other = $this->owner();
        app(TenantContext::class)->forOwner($owner, function () use ($other): void {
            try {
                app(TenantContext::class)->forOwnerIfNeeded($other, fn () => $this->fail('Fremder Kontext wurde wiederverwendet.'));
                $this->fail('Fremde Owner-Zuordnung wurde akzeptiert.');
            } catch (AuthorizationException) {
                $this->assertTrue(tenancy()->initialized);
            }
        });
        $this->assertFalse(tenancy()->initialized);
        Livewire::test(Overview::class)->assertSee('Abo-Teststation');
        $this->assertFalse(tenancy()->initialized);
    }
}
