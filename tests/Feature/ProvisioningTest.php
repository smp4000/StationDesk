<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenant;
use App\Models\Employee;
use App\Models\Owner;
use App\Models\Station;
use App\Models\Tenant;
use App\Tenancy\Provisioner;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Provisionierung auf echtem MySQL mit eigenem eingeschränktem Datenbanknutzer pro Testmandant. */
class ProvisioningTest extends TestCase
{
    private ?Tenant $fixtureTenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Nur in der isolierten Testinstanz ausführbar.');
        }
        $dataDirectory = DB::connection('central')->selectOne('SELECT @@datadir AS path')->path;
        if (! str_contains(str_replace('\\', '/', $dataDirectory), '/StationDesk/.runtime/mysql-tests/')) {
            throw new RuntimeException('Die erwartete temporäre MySQL-Instanz fehlt.');
        }
        config(['database.connections.provisioner.username' => 'root', 'database.connections.provisioner.password' => '']);
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        if ($this->fixtureTenant !== null) {
            $tenant = $this->fixtureTenant;
            $db = DB::connection('central');
            // Die Namen stammen ausschließlich aus der unten erzeugten Testfixture.
            $db->statement('DROP DATABASE IF EXISTS '.$tenant->tenancy_db_name);
            $db->statement("DROP USER IF EXISTS '".$tenant->tenancy_db_username."'@'%'");
            foreach (['audit_events', 'subscriptions', 'provisioning_runs', 'registration_requests', 'owners'] as $table) {
                $db->table($table)->where('tenant_id', $tenant->id)->delete();
            }
            $tenant->delete();
        }
        parent::tearDown();
    }

    private function registrationFixture(bool $verified = true): Owner
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => (string) Str::uuid(), 'company_name' => 'Testbetrieb', 'provisioning_status' => 'pending',
            'tenancy_db_name' => 'sd_t_'.bin2hex(random_bytes(16)),
            'tenancy_db_username' => 'sdu_'.bin2hex(random_bytes(14)),
            'tenancy_db_password' => bin2hex(random_bytes(32)),
        ])->save();
        $this->fixtureTenant = $tenant;
        $owner = Owner::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => $verified ? now() : null]);
        DB::connection('central')->table('registration_requests')->insert([
            'tenant_id' => $tenant->id, 'station_id' => (string) Str::uuid(), 'employee_id' => (string) Str::uuid(),
            'station_name' => 'Erste Teststation', 'station_street' => 'Testweg 1', 'station_postal_code' => '36037',
            'station_city' => 'Fulda', 'station_country_code' => 'DE', 'gross_cents' => 100, 'tax_basis_points' => 1900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $owner;
    }

    public function test_success_creates_one_station_chef_and_thirty_day_trial_and_retry_changes_nothing(): void
    {
        $owner = $this->registrationFixture();
        $this->travelTo(now()->startOfSecond());
        app(Provisioner::class)->provision($owner->tenant_id);
        $first = DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertSame(now()->toDateTimeString(), $first->trial_started_at);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $first->trial_ends_at);
        $this->travel(1)->days();
        app(Provisioner::class)->provision($owner->tenant_id);
        $second = DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertSame($first->trial_started_at, $second->trial_started_at);
        $this->assertSame(100, $second->gross_cents);
        app(TenantContext::class)->forOwner($owner, function () use ($owner): void {
            $this->assertSame(1, Station::query()->count());
            $this->assertSame($owner->id, Employee::query()->sole()->owner_id);
            $this->assertSame(1, DB::connection('tenant')->table('employee_station_assignments')->count());
        });
        $this->assertSame(1, DB::connection('central')->table('provisioning_runs')->where('tenant_id', $owner->tenant_id)->count());
        $this->travelBack();
    }

    public function test_unverified_registration_creates_neither_database_nor_trial(): void
    {
        $owner = $this->registrationFixture(false);
        try {
            app(Provisioner::class)->provision($owner->tenant_id);
            $this->fail('Unbestätigte Registrierung wurde provisioniert.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('validation', $exception->getMessage());
        }
        $this->assertSame(0, DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->count());
        $this->assertNull(DB::connection('central')->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->fixtureTenant->tenancy_db_name]));
    }

    public function test_worker_failure_is_sanitized_and_retry_can_complete(): void
    {
        $owner = $this->registrationFixture();
        config(['database.connections.provisioner.username' => null]);
        try {
            app(Provisioner::class)->provision($owner->tenant_id);
            $this->fail('Fehlender Worker-Zugang wurde ignoriert.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($this->fixtureTenant->tenancy_db_password, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('failed', $this->fixtureTenant->fresh()->provisioning_status);
        config(['database.connections.provisioner.username' => 'root']);
        app(Provisioner::class)->provision($owner->tenant_id);
        $this->assertSame('ready', $this->fixtureTenant->fresh()->provisioning_status);
        $this->assertSame(2, DB::connection('central')->table('provisioning_runs')->where('tenant_id', $owner->tenant_id)->count());
    }

    public function test_tenant_database_user_cannot_query_landlord_even_with_explicit_sql(): void
    {
        $owner = $this->registrationFixture();
        app(Provisioner::class)->provision($owner->tenant_id);
        $this->expectException(QueryException::class);
        app(TenantContext::class)->forOwner($owner, fn () => DB::connection('tenant')->select('SELECT * FROM stationdesk_test.owners'));
    }

    public function test_tenant_runtime_user_cannot_drop_its_own_station_table(): void
    {
        $owner = $this->registrationFixture();
        app(Provisioner::class)->provision($owner->tenant_id);
        $this->expectException(QueryException::class);
        app(TenantContext::class)->forOwner($owner, fn () => DB::connection('tenant')->statement('DROP TABLE stations'));
    }

    public function test_email_verification_dispatches_only_an_id_to_the_central_worker(): void
    {
        $owner = $this->registrationFixture();
        Queue::fake();
        event(new Verified($owner));
        Queue::assertPushed(ProvisionTenant::class, fn (ProvisionTenant $job) => $job->tenantId === $owner->tenant_id && $job->connection === 'provisioning');
    }

    public function test_ready_owner_sees_only_his_real_station_in_panel(): void
    {
        $this->withoutVite();
        $owner = $this->registrationFixture();
        app(Provisioner::class)->provision($owner->tenant_id);
        $this->actingAs($owner, 'web')->get('/owner/overview')->assertOk()->assertSee('Erste Teststation');
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_pending_owner_gets_provisioning_screen_without_a_trial(): void
    {
        $this->withoutVite();
        $owner = $this->registrationFixture();
        $this->actingAs($owner, 'web')->get('/owner/overview')->assertStatus(202)->assertSee('wird vorbereitet');
        $this->assertSame(0, DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->count());
    }
}
