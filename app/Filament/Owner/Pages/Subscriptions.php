<?php

namespace App\Filament\Owner\Pages;

use App\Billing\ManageSubscriptions;
use App\Models\Station;
use App\Tenancy\TenantContext;
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

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public ?array $cancellation = null;

    #[Locked]
    public ?array $reactivation = null;

    /** Zeigt den aktuellen Kündigungsvorgang samt unverändertem Monatsrhythmus vor der Rücknahme. */
    public function prepareReactivation(int $id): void
    {
        $quote = app(ManageSubscriptions::class)->quote($id);
        abort_unless($quote['can_reactivate'] && $quote['cancellation_id'], 409);
        $name = app(TenantContext::class)->forOwnerIfNeeded(app(ManageSubscriptions::class)->owner(),
            fn () => Station::query()->findOrFail($quote['station_id'])->name);
        $this->resetValidation();
        $this->reactivation = ['id' => $id, 'cancellation_id' => (int) $quote['cancellation_id'], 'station' => $name, 'ended' => $quote['ended']];
        $this->dispatch('open-modal', id: 'reactivate-subscription');
    }

    /** Eine alte Dialogbestätigung darf keine zwischenzeitlich erneut erklärte Kündigung aufheben. */
    public function confirmReactivation(): void
    {
        abort_unless($this->reactivation !== null, 409);
        app(ManageSubscriptions::class)->reactivate($this->reactivation['id'], $this->reactivation['cancellation_id']);
        $this->reactivation = null;
        $this->dispatch('close-modal', id: 'reactivate-subscription');
        Notification::make()->title('Testabo wird fortgesetzt')->body('Der bisherige Monatsrhythmus und Stationspreis bleiben erhalten.')->success()->send();
    }

    /** Fragt den Endtermin serverseitig ab und zeigt den betroffenen Standort vor jeder Kündigung. */
    public function prepareCancellation(int $id): void
    {
        $quote = app(ManageSubscriptions::class)->quote($id);
        $name = app(TenantContext::class)->forOwnerIfNeeded(app(ManageSubscriptions::class)->owner(),
            fn () => Station::query()->findOrFail($quote['station_id'])->name);
        $this->resetValidation();
        $this->cancellation = ['id' => $id, 'end' => $quote['cancellation_end'], 'station' => $name, 'last_cancellation_id' => (int) $quote['cancellation_id']];
        $this->dispatch('open-modal', id: 'cancel-subscription');
    }

    /** Prüft Eigentümer und angezeigten Termin erneut; Fehler lassen den Dialog zur Korrektur offen. */
    public function confirmCancellation(): void
    {
        abort_unless($this->cancellation !== null, 409);
        app(ManageSubscriptions::class)->cancel($this->cancellation['id'], $this->cancellation['end'], $this->cancellation['last_cancellation_id']);
        $this->cancellation = null;
        $this->dispatch('close-modal', id: 'cancel-subscription');
        Notification::make()->title('Kündigung vorgemerkt')->body('Den bestätigten Endtermin findest du bei deinem Stationsabo.')->success()->send();
    }

    /** Verbindet zentrale Vertragsreferenzen ausschließlich mit Stationen des bereits geprüften Mandantenkontexts. */
    protected function getViewData(): array
    {
        $subscriptions = app(ManageSubscriptions::class)->all();

        $names = app(TenantContext::class)->forOwnerIfNeeded(app(ManageSubscriptions::class)->owner(),
            fn () => Station::query()->pluck('name', 'id'));

        return ['subscriptions' => $subscriptions, 'stationNames' => $names];
    }
}
