<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/** Prüft Schemazugang mit den tatsächlichen Tenant-Zugangsdaten, auch in der lokalen Umgebung. */
class TenantDatabaseBootstrapper extends DatabaseTenancyBootstrapper
{
    /** Die leere Verbindungsvorlage besitzt bewusst keine globalen DB-Metadatenrechte. */
    public function bootstrap(Tenant $tenant): void
    {
        $this->database->connectToTenant($tenant);
        DB::connection('tenant')->getPdo();
    }
}
