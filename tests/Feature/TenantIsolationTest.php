<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Owner;
use App\Models\Station;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** Echte MySQL-Negativtests; ausschließlich explizit benannte temporäre Testschemata werden verändert. */
class TenantIsolationTest extends TestCase
{
    private array $testTenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Diese Tests benötigen die isolierte stationdesk_test-Datenbank.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->testTenants as $tenant) {
            $name = $tenant->tenancy_db_name;
            if (! preg_match('/^sd_test_[a-f0-9]{32}$/D', $name)) {
                throw new RuntimeException('Unerwartetes Test-Schema.');
            }
            DB::connection('central')->statement('DROP DATABASE IF EXISTS '.$name);
            Owner::query()->where('tenant_id', $tenant->id)->delete();
            $tenant->delete();
        }
        parent::tearDown();
    }

    private function tenantFixture(): Tenant
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => (string) Str::uuid(), 'company_name' => 'Testbetrieb',
            'provisioning_status' => 'ready', 'tenancy_db_name' => 'sd_test_'.str_replace('-', '', (string) Str::uuid()),
            // Ausschließlich die kurzlebige, lokal gebundene Testinstanz enthält diesen Benutzer.
            'tenancy_db_username' => 'root', 'tenancy_db_password' => '',
        ])->save();
        $this->testTenants[] = $tenant;
        DB::connection('central')->statement('CREATE DATABASE '.$tenant->tenancy_db_name.' CHARACTER SET utf8mb4');
        app(TenantContext::class)->run($tenant, function (): void {
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        });

        return $tenant;
    }

    public function test_fachmodels_fail_closed_without_context(): void
    {
        $this->expectException(LogicException::class);
        Station::query()->count();
    }

    public function test_two_tenants_cannot_read_each_others_station_cache_or_file(): void
    {
        $first = $this->tenantFixture();
        $second = $this->tenantFixture();
        $context = app(TenantContext::class);
        $context->run($first, function (): void {
            $station = new Station;
            $station->forceFill(['id' => (string) Str::uuid(), 'name' => 'Nur Mandant A', 'street' => 'Testweg 1', 'postal_code' => '36037', 'city' => 'Fulda', 'country_code' => 'DE'])->save();
            Cache::put('isolation-check', 'only-a', 60);
            Storage::disk('local')->put('isolation-check.txt', 'only-a');
        });
        $context->run($second, function (): void {
            $this->assertSame(0, Station::query()->count());
            $this->assertNull(Cache::get('isolation-check'));
            $this->assertFalse(Storage::disk('local')->exists('isolation-check.txt'));
        });
        $context->run($first, function (): void {
            $this->assertSame('Nur Mandant A', Station::query()->sole()->name);
            $this->assertSame('only-a', Cache::get('isolation-check'));
            $this->assertSame('only-a', Storage::disk('local')->get('isolation-check.txt'));
            Storage::disk('local')->delete('isolation-check.txt');
        });
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_hydrated_model_cannot_be_reused_in_another_tenant(): void
    {
        $first = $this->tenantFixture();
        $second = $this->tenantFixture();
        $context = app(TenantContext::class);
        $employee = $context->run($first, function (): Employee {
            $record = new Employee;
            $record->forceFill(['id' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Chef'])->save();

            return Employee::query()->sole();
        });
        $this->expectException(LogicException::class);
        $context->run($second, fn () => $employee->refresh());
    }

    public function test_exception_restores_connection_cache_and_filesystem(): void
    {
        $tenant = $this->tenantFixture();
        $path = storage_path();
        $cache = Cache::getDefaultDriver();
        try {
            app(TenantContext::class)->run($tenant, fn () => throw new RuntimeException('Testfehler'));
        } catch (RuntimeException $exception) {
            $this->assertSame('Testfehler', $exception->getMessage());
        }
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame($cache, Cache::getDefaultDriver());
        $this->assertSame($path, storage_path());
    }

    public function test_owner_context_uses_persisted_assignment_not_mutated_request_model(): void
    {
        $first = $this->tenantFixture();
        $second = $this->tenantFixture();
        $owner = Owner::factory()->create(['tenant_id' => $first->id]);
        $owner->tenant_id = $second->id;
        $actual = app(TenantContext::class)->forOwner($owner, fn () => tenant('id'));
        $this->assertSame($first->id, $actual);
    }

    public function test_unverified_owner_cannot_initialize_tenancy(): void
    {
        $tenant = $this->tenantFixture();
        $owner = Owner::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => null]);
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->forOwner($owner, fn () => tenant('id'));
    }

    public function test_owner_session_does_not_authenticate_platform_guard(): void
    {
        $tenant = $this->tenantFixture();
        $owner = Owner::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($owner, 'web')->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_platform_account_must_enroll_totp_before_dashboard(): void
    {
        $admin = SuperAdmin::factory()->create();
        try {
            $this->actingAs($admin, 'admin')->get('/admin')->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
        } finally {
            $admin->delete();
        }
    }
}
