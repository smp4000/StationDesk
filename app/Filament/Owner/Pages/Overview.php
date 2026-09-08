<?php

namespace App\Filament\Owner\Pages;

use App\Models\Station;
use Filament\Pages\Page;

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
        return ['stations' => Station::query()->orderBy('name')->get()];
    }
}
