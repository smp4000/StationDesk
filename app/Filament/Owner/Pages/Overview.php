<?php

namespace App\Filament\Owner\Pages;

use App\Models\Owner;
use App\Models\Station;
use App\Tenancy\TenantContext;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/** Stationsübersicht im verpflichtend initialisierten Mandantenkontext. */
class Overview extends Page
{
    protected string $view = 'filament.owner.pages.overview';

    protected static ?string $title = 'Deine Tankstellen';

    protected static ?string $navigationLabel = 'Übersicht';

    protected static ?int $navigationSort = 1;

    /** Liefert ausschließlich tatsächlich vorhandene Stationen aus der aktiven Datenbank. */
    protected function getViewData(): array
    {
        $owner = auth('web')->user();
        abort_unless($owner instanceof Owner, 403);

        return app(TenantContext::class)->forOwnerIfNeeded($owner, fn () => [
            // Die Ansicht erhält reine Werte; kein Fachmodell verlässt seinen begrenzten Kontext.
            'stations' => Station::query()->orderBy('name')->get()->map(fn (Station $station) => (object) $station->getAttributes()),
            'subscriptions' => DB::connection('central')->table('subscriptions')->where('tenant_id', tenant()->getTenantKey())->get()->keyBy('station_id'),
        ]);
    }
}
