<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/** Trennt Cache und Locks in einem eigenen Store mit tenantabhängigen Schlüsseln. */
class TenantCacheBootstrapper implements TenancyBootstrapper
{
    private ?string $previousStore = null;

    /** Nutzt eine explizite zentrale Cache-Verbindung; fachliche Schlüssel bleiben getrennt. */
    public function bootstrap(Tenant $tenant): void
    {
        $this->previousStore = config('cache.default');
        config(['cache.stores.tenant' => array_replace(config('cache.stores.database'), [
            'connection' => 'central', 'lock_connection' => 'central', 'prefix' => 'tenant:'.$tenant->getTenantKey().':',
        ])]);
        Cache::purge('tenant');
        Cache::setDefaultDriver('tenant');
    }

    /** Gibt die vorherige Konfiguration auch nach einem fehlgeschlagenen Job wieder frei. */
    public function revert(): void
    {
        Cache::purge('tenant');
        if ($this->previousStore !== null) {
            Cache::setDefaultDriver($this->previousStore);
            $this->previousStore = null;
        }
    }
}
