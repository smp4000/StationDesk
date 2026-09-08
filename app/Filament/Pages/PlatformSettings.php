<?php

namespace App\Filament\Pages;

use App\Models\SuperAdmin;
use App\Settings\PlatformSettingsStore;
use App\Settings\SendPlatformTestMail;
use App\Settings\TestFintsConnection;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;

/** Vier unabhängig speicherbare Plattformbereiche; Geheimnisse bleiben nach dem Laden leer. */
class PlatformSettings extends Page
{
    protected string $view = 'filament.pages.platform-settings';

    protected static ?string $title = 'Plattform-Einstellungen';

    protected static ?string $navigationLabel = 'Einstellungen';

    public array $data = [];

    public string $testRecipient = '';

    #[Locked]
    public string $fintsConnectionResult = '';

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

    /** Sendet nur auf ausdrücklichen Button-Klick mit den gespeicherten SMTP-Einstellungen. */
    public function sendTestMail(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $this->resetValidation('testRecipient');
        try {
            app(SendPlatformTestMail::class)->send($this->testRecipient);
        } catch (ValidationException $exception) {
            $this->addError('testRecipient', $exception->errors()['testRecipient'][0]);

            return;
        } catch (RuntimeException) {
            Notification::make()->title('Testmail nicht bestätigt')->body('Bitte Server, Port, TLS und Zugangsdaten prüfen. Bei einem Verbindungsabbruch kann die Mail bereits angenommen worden sein.')->danger()->send();

            return;
        }
        Notification::make()->title('Testmail vom SMTP-Server angenommen')->body('Bitte Posteingang und Spamordner prüfen. Die Annahme bestätigt noch keine Zustellung.')->success()->send();
    }

    /** Startet einen getrennten HTTPS-Test ohne Bankzugangsdaten oder Zahlungsaufträge. */
    public function testFintsConnection(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $this->resetValidation('fintsTest');
        $this->fintsConnectionResult = '';
        try {
            $status = app(TestFintsConnection::class)->run();
            $this->fintsConnectionResult = 'HTTPS-Endpunkt erreicht, HTTP-Status '.$status.'. TLS-Zertifikat geprüft. Banklogin und FinTS-Funktion sind damit noch nicht bestätigt.';
        } catch (ValidationException $exception) {
            $this->addError('fintsTest', $exception->errors()['fintsTest'][0]);
        } catch (RuntimeException) {
            $this->addError('fintsTest', 'HTTPS-Verbindung nicht bestätigt. Bitte Netzwerk, Bankadresse und Zertifikatsprüfung kontrollieren.');
        }
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
