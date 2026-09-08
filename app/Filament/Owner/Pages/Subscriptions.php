<?php

namespace App\Filament\Owner\Pages;

use App\Billing\ManageSubscriptions;
use App\Models\Station;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/** Eigene Stationsabos mit Preisstand und zweistufig bestätigter Kündigung im lokalen Testbetrieb. */
class Subscriptions extends Page
{
    protected string $view = 'filament.owner.pages.subscriptions';

    protected static ?string $title = 'Abos & Laufzeiten';

    protected static ?string $navigationLabel = 'Abos & Laufzeiten';

    protected static ?int $navigationSort = 2;

    #[Locked]
    public ?array $cancellation = null;

    /** Fragt den Endtermin serverseitig ab und zeigt den betroffenen Standort vor jeder Kündigung. */
    public function prepareCancellation(int $id): void
    {
        $quote = app(ManageSubscriptions::class)->quote($id);
        $station = Station::query()->findOrFail($quote['station_id']);
        $this->resetValidation();
        $this->cancellation = ['id' => $id, 'end' => $quote['cancellation_end'], 'station' => $station->name];
        $this->dispatch('open-modal', id: 'cancel-subscription');
    }

    /** Prüft Eigentümer und angezeigten Termin erneut; Fehler lassen den Dialog zur Korrektur offen. */
    public function confirmCancellation(): void
    {
        abort_unless($this->cancellation !== null, 409);
        app(ManageSubscriptions::class)->cancel($this->cancellation['id'], $this->cancellation['end']);
        $this->cancellation = null;
        $this->dispatch('close-modal', id: 'cancel-subscription');
        Notification::make()->title('Kündigung vorgemerkt')->body('Den bestätigten Endtermin findest du bei deinem Stationsabo.')->success()->send();
    }

    /** Verbindet zentrale Vertragsreferenzen ausschließlich mit Stationen des bereits geprüften Mandantenkontexts. */
    protected function getViewData(): array
    {
        $subscriptions = app(ManageSubscriptions::class)->all();

        return ['subscriptions' => $subscriptions, 'stationNames' => Station::query()->pluck('name', 'id')];
    }
}
