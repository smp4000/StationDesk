<?php

namespace App\Filament\Pages;

use App\Models\SuperAdmin;
use App\Settings\PlatformSettingsStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Vier unabhängig speicherbare Plattformbereiche; Geheimnisse bleiben nach dem Laden leer. */
class PlatformSettings extends Page
{
    protected string $view = 'filament.pages.platform-settings';

    protected static ?string $title = 'Plattform-Einstellungen';

    protected static ?string $navigationLabel = 'Einstellungen';

    public array $data = [];

    #[Locked]
    public array $revisions = [];

    #[Locked]
    public array $secretsSaved = [];

    /** Der normale Panelzugang prüft zusätzlich den abgeschlossenen MFA-Anmeldeablauf. */
    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof SuperAdmin && filled($admin->getAppAuthenticationSecret());
    }

    /** Initialisiert alle Tabs, damit ungespeicherte Eingaben beim Tabwechsel erhalten bleiben. */
    public function mount(): void
    {
        foreach (['billing', 'creditor', 'smtp', 'fints'] as $group) {
            $this->loadGroup($group);
        }
    }

    /** Ordnet Validierungsfehler dem betreffenden Formular zu und leert Geheimnisfelder nach Erfolg. */
    public function save(string $group): void
    {
        $store = app(PlatformSettingsStore::class);
        $store->authorize();
        abort_unless(in_array($group, ['billing', 'creditor', 'smtp', 'fints'], true), 404);
        $this->resetValidation();
        try {
            $store->save($group, $this->data[$group] ?? [], $this->revisions[$group] ?? 0);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError('data.'.$group.'.'.$key, $messages[0]);
            }

            return;
        }
        $this->loadGroup($group);
        Notification::make()->title('Einstellungen gespeichert.')->success()->send();
    }

    /** Explizites Neuladen verwirft nur die Eingaben des gewählten Bereichs. */
    public function reloadGroup(string $group): void
    {
        $this->loadGroup($group);
        $this->resetValidation();
    }

    /** Liefert nur öffentliche Historienmetadaten für die versionierten Bereiche. */
    protected function getViewData(): array
    {
        $store = app(PlatformSettingsStore::class);

        return ['history' => ['billing' => $store->history('billing'), 'creditor' => $store->history('creditor')]];
    }

    /** Die Bereichsliste und Geheimnisfilterung werden im Dienst zentral erzwungen. */
    private function loadGroup(string $group): void
    {
        $state = app(PlatformSettingsStore::class)->read($group);
        $this->data[$group] = $state['data'];
        $this->revisions[$group] = $state['revision'];
        $this->secretsSaved[$group] = $state['secret_saved'];
    }
}
