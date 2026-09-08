<?php

namespace Tests\Feature;

use App\Billing\ManageSubscriptions;
use App\Filament\Owner\Pages\Subscriptions;
use App\Models\Owner;
use App\Models\Tenant;
use App\Tenancy\Provisioner;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
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
        app(TenantContext::class)->forOwner($owner->fresh(), function () use ($id): void {
            $page = Livewire::test(Subscriptions::class)->assertSee('Abo-Teststation')->assertSee('1,00 € brutto')
                ->call('prepareCancellation', $id)->assertDispatched('open-modal');
            $this->assertNull(DB::connection('central')->table('subscriptions')->where('id', $id)->value('cancelled_at'));
            $page->call('confirmCancellation')->assertDispatched('close-modal')->assertNotified('Kündigung vorgemerkt')->assertSet('cancellation', null);
        });
        $this->assertDatabaseHas('subscription_cancellations', ['subscription_id' => $id, 'requested_by' => $owner->id], 'central');
    }
}
