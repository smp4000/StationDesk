<?php

namespace App\Filament\Pages;

use App\Jobs\ProvisionTenant;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/** Technische Plattformübersicht ohne Zugriff auf mandanteneigene Personal- oder Stationsinhalte. */
class Provisioning extends Page
{
    protected string $view = 'filament.pages.provisioning';

    protected static ?string $title = 'Mandanten & Bereitstellung';

    protected static ?string $navigationLabel = 'Mandanten';

    /** Stellt Status und die letzten bereinigten Fehler bereit, ohne Geheimnisfelder zu serialisieren. */
    protected function getViewData(): array
    {
        return [
            'tenants' => Tenant::query()->select(['id', 'company_name', 'provisioning_status', 'created_at'])->latest()->limit(100)->get(),
            'runs' => DB::connection('central')->table('provisioning_runs')->orderByDesc('id')->limit(20)->get(),
        ];
    }

    /** Prüft Rolle und Zustand erneut serverseitig vor jedem Retry und protokolliert den Auftrag. */
    public function retry(string $tenantId): void
    {
        $admin = Filament::auth()->user();
        abort_unless($admin instanceof SuperAdmin, 403);
        $tenant = Tenant::query()->findOrFail($tenantId);
        abort_unless($tenant->provisioning_status === 'failed', 409);
        DB::connection('central')->transaction(function () use ($tenant, $admin): void {
            DB::connection('central')->table('audit_events')->insert([
                'tenant_id' => $tenant->id, 'actor_type' => 'super_admin', 'actor_id' => (string) $admin->id,
                'action' => 'tenant.provisioning_retry_requested', 'subject_id' => $tenant->id, 'occurred_at' => now(),
            ]);
            ProvisionTenant::dispatch($tenant->id)->afterCommit();
        });
        Notification::make()->title('Erneute Bereitstellung wurde angefordert.')->success()->send();
    }
}
