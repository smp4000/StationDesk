<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/** Zentrale Registry: technischer Zustand, keine mandantenweite Abo-Lifecycle-Steuerung. */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $guarded = ['*'];

    protected $hidden = ['tenancy_db_password', 'tenancy_db_username', 'tenancy_db_name'];

    /** Alle Registry-Daten bleiben in echten Spalten statt in relationalen JSON-Listen. */
    public static function getCustomColumns(): array
    {
        return ['id', 'company_name', 'provisioning_status', 'tenancy_db_name', 'tenancy_db_username', 'tenancy_db_password', 'created_at', 'updated_at'];
    }

    /** Schützt das mandanteneigene Datenbankpasswort auch innerhalb zentraler Sicherungen. */
    protected function casts(): array
    {
        return ['tenancy_db_password' => 'encrypted'];
    }
}
