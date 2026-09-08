<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/** Fehler vor vollständigem Bootstrap müssen ebenfalls alle globalen Laravel-Komponenten zurücksetzen. */
class FailedTenantBootstrapTest extends TestCase
{
    public function test_missing_database_restores_context_even_when_initialization_itself_fails(): void
    {
        $path = storage_path();
        $cache = Cache::getDefaultDriver();
        $disk = config('filesystems.disks.local.root');
        $tenant = (new Tenant)->forceFill([
            'id' => (string) Str::uuid(), 'tenancy_db_name' => 'sd_missing_'.bin2hex(random_bytes(16)),
            'tenancy_db_username' => 'root', 'tenancy_db_password' => '',
        ]);
        $failed = false;
        try {
            app(TenantContext::class)->run($tenant, fn () => $this->fail('Callback darf nicht erreicht werden.'));
        } catch (Throwable) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenant());
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame($cache, Cache::getDefaultDriver());
        $this->assertSame($path, storage_path());
        $this->assertSame($disk, config('filesystems.disks.local.root'));
    }
}
