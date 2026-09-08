<?php

namespace App\Tenancy;

use App\Billing\MonthlyPrice;
use App\Models\Employee;
use App\Models\Owner;
use App\Models\Station;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Wiederaufnehmbare Schema-Bereitstellung; zentrale Abschlussdaten werden erst nach Fachdatenerstellung geschrieben. */
class Provisioner
{
    /** Serialisiert Versuche mit einer verbindungsgebundenen MySQL-Sperre und schützt Geheimnisse in Fehlerfällen. */
    public function provision(string $tenantId): void
    {
        $central = DB::connection('central');
        $lockName = 'provision:'.hash('sha256', $tenantId);
        $lockName = substr($lockName, 0, 64);
        if ((int) $central->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->acquired !== 1) {
            throw new RuntimeException('Provisionierung läuft bereits.');
        }
        $runId = null;
        $step = 'validation';
        try {
            $tenant = Tenant::query()->findOrFail($tenantId);
            if ($tenant->provisioning_status === 'ready') {
                return;
            }
            $owner = Owner::query()->where('tenant_id', $tenantId)->firstOrFail();
            if (! $owner->hasVerifiedEmail()) {
                throw new RuntimeException('E-Mail-Bestätigung fehlt.');
            }
            $registration = $central->table('registration_requests')->where('tenant_id', $tenantId)->first();
            if (! $registration) {
                throw new RuntimeException('Registrierungsauftrag fehlt.');
            }
            new MonthlyPrice($registration->gross_cents, $registration->tax_basis_points);
            $runId = $central->table('provisioning_runs')->insertGetId([
                'tenant_id' => $tenantId, 'status' => 'running', 'step' => $step, 'started_at' => now(),
            ]);
            $tenant->forceFill(['provisioning_status' => 'provisioning'])->save();
            $step = 'database';
            $central->table('provisioning_runs')->where('id', $runId)->update(['step' => $step]);
            $this->prepareDatabase($tenant);
            $step = 'migration';
            $central->table('provisioning_runs')->where('id', $runId)->update(['step' => $step]);
            app(TenantContext::class)->run($tenant, function () use ($registration, $owner): void {
                $runtimeConnection = config('database.connections.tenant');
                try {
                    config(['database.connections.tenant' => array_replace($runtimeConnection, [
                        'username' => config('database.connections.provisioner.username'),
                        'password' => config('database.connections.provisioner.password'),
                    ])]);
                    DB::purge('tenant');
                    $exitCode = Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
                } finally {
                    config(['database.connections.tenant' => $runtimeConnection]);
                    DB::purge('tenant');
                }
                if ($exitCode !== 0) {
                    throw new RuntimeException('Mandantenmigration fehlgeschlagen.');
                }
                DB::connection('tenant')->transaction(function () use ($registration, $owner): void {
                    $station = Station::query()->find($registration->station_id);
                    if (! $station) {
                        (new Station)->forceFill([
                            'id' => $registration->station_id, 'name' => $registration->station_name,
                            'street' => $registration->station_street, 'postal_code' => $registration->station_postal_code,
                            'city' => $registration->station_city, 'country_code' => $registration->station_country_code,
                        ])->save();
                    }
                    if (! Employee::query()->find($registration->employee_id)) {
                        (new Employee)->forceFill([
                            'id' => $registration->employee_id, 'owner_id' => $owner->id,
                            'first_name' => $owner->first_name, 'last_name' => $owner->last_name,
                        ])->save();
                    }
                    DB::connection('tenant')->table('employee_station_assignments')->insertOrIgnore([
                        'employee_id' => $registration->employee_id, 'station_id' => $registration->station_id,
                    ]);
                });
            });
            $step = 'completion';
            $central->transaction(function () use ($central, $tenant, $registration, $runId): void {
                $start = now()->toImmutable();
                $end = $start->addDays(30);
                $central->table('subscriptions')->insert([
                    'tenant_id' => $tenant->id, 'station_id' => $registration->station_id, 'status' => 'trial',
                    'gross_cents' => $registration->gross_cents, 'tax_basis_points' => $registration->tax_basis_points,
                    'currency' => 'EUR', 'trial_started_at' => $start, 'trial_ends_at' => $end, 'billing_anchor_at' => $end,
                    'created_at' => $start, 'updated_at' => $start,
                ]);
                $central->table('registration_requests')->where('id', $registration->id)->update(['completed_at' => $start]);
                $tenant->forceFill(['provisioning_status' => 'ready'])->save();
                $central->table('provisioning_runs')->where('id', $runId)->update(['status' => 'succeeded', 'step' => 'completion', 'finished_at' => $start]);
                $central->table('audit_events')->insert([
                    'tenant_id' => $tenant->id, 'actor_type' => 'system', 'action' => 'tenant.provisioned',
                    'subject_id' => $tenant->id, 'occurred_at' => $start,
                ]);
            });
        } catch (Throwable) {
            if ($runId !== null) {
                $central->table('provisioning_runs')->where('id', $runId)->update([
                    'status' => 'failed', 'step' => $step, 'error_code' => 'provisioning.'.$step.'_failed', 'finished_at' => now(),
                ]);
                $central->table('tenants')->where('id', $tenantId)->update(['provisioning_status' => 'failed']);
            }
            // PDO-Fehler können SQL mit Datenbankpasswörtern enthalten; daher keine Exception-Verkettung.
            throw new RuntimeException('Mandantenbereitstellung fehlgeschlagen; Schritt: '.$step.'.');
        } finally {
            $central->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    /** Erstellt ausschließlich intern benannte Schemen; Webprozesse besitzen diesen DDL-Zugang nicht. */
    private function prepareDatabase(Tenant $tenant): void
    {
        $database = $tenant->tenancy_db_name;
        $username = $tenant->tenancy_db_username;
        if (! preg_match('/^sd_t_[a-f0-9]{32}$/D', $database) || ! preg_match('/^sdu_[a-f0-9]{28}$/D', $username)) {
            throw new RuntimeException('Ungültige interne Datenbankreferenz.');
        }
        if (! config('database.connections.provisioner.username')) {
            throw new RuntimeException('Separater Worker-Datenbankzugang fehlt.');
        }
        $provisioner = DB::connection('provisioner');
        $password = $provisioner->getPdo()->quote($tenant->tenancy_db_password);
        // Unterstriche sind in MySQL-Datenbankrechten sonst Platzhalter für beliebige Zeichen.
        $grantDatabase = str_replace('_', '\\_', $database);
        $provisioner->statement('CREATE DATABASE IF NOT EXISTS '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $provisioner->statement("CREATE USER IF NOT EXISTS '".$username."'@'%' IDENTIFIED BY ".$password);
        $provisioner->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$grantDatabase."`.* TO '".$username."'@'%'");
    }
}
