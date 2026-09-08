<?php

namespace App\Filament\Owner\Pages;

use App\Billing\ManageSubscriptions;
use App\Settings\OwnerAppearance;
use Filament\Pages\Page;

/** Gemeinsame Kunden-Einstellungen mit eigenem Tab für die stationsbezogene Vertragsverwaltung. */
class Settings extends Page
{
    protected string $view = 'filament.owner.pages.settings';

    protected static ?string $title = 'Einstellungen';

    protected static ?int $navigationSort = 2;

    public string $colorScheme = 'petrol';

    /** Prüft die persistierte Owner-Zuordnung auch beim erstmaligen Öffnen der Tab-Seite. */
    public function mount(): void
    {
        app(ManageSubscriptions::class)->owner();
        $this->colorScheme = app(OwnerAppearance::class)->currentKey();
    }

    /** Die explizite Speicherung übernimmt die Palette für alle folgenden Anfragen dieses Benutzers. */
    public function saveAppearance(): void
    {
        app(ManageSubscriptions::class)->owner();
        app(OwnerAppearance::class)->save($this->colorScheme);
        $this->redirect(static::getUrl().'?tab=appearance', navigate: false);
    }
}
