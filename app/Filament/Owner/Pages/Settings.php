<?php

namespace App\Filament\Owner\Pages;

use App\Billing\ManageSubscriptions;
use Filament\Pages\Page;

/** Gemeinsame Kunden-Einstellungen mit eigenem Tab für die stationsbezogene Vertragsverwaltung. */
class Settings extends Page
{
    protected string $view = 'filament.owner.pages.settings';

    protected static ?string $title = 'Einstellungen';

    protected static ?int $navigationSort = 2;

    /** Prüft die persistierte Owner-Zuordnung auch beim erstmaligen Öffnen der Tab-Seite. */
    public function mount(): void
    {
        app(ManageSubscriptions::class)->owner();
    }
}
