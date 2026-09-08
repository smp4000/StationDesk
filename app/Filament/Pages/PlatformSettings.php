<?php

namespace App\Filament\Pages;

use App\Models\SuperAdmin;
use App\Settings\BankDirectory;
use App\Settings\FintsReadOnlyTest;
use App\Settings\PlatformSettingsStore;
use App\Settings\SendPlatformTestMail;
use App\Settings\TestFintsConnection;
use BackedEnum;
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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public array $data = [];

    public string $testRecipient = '';

    public string $bankCode = '';

    public string $accountNumber = '';

    public string $ibanCheck = '';

    #[Locked]
    public array $ibanResult = [];

    public string $bankLogin = '';

    public string $bankPin = '';

    public string $bankChoice = '';

    #[Locked]
    public array $bankState = [];

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

    /** Erstellt auf ausdrücklichen Wunsch nur einen Standardvorschlag aus aktueller Bankzuordnung. */
    public function proposeIban(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $this->resetValidation();
        $this->ibanResult = [];
        $accountNumber = $this->accountNumber;
        $this->accountNumber = '';
        try {
            $this->ibanResult = app(BankDirectory::class)->propose($this->bankCode, $accountNumber);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    /** Prüft eine vom Nutzer eingetragene IBAN; gespeicherte Gläubiger-IBANs bleiben verborgen. */
    public function checkIban(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $this->resetValidation();
        $this->ibanResult = [];
        try {
            $this->ibanResult = app(BankDirectory::class)->check($this->ibanCheck);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    /** Übernimmt das sichtbare Ergebnis ausdrücklich nur in den Entwurf; Gläubiger speichern bleibt separat. */
    public function applyIban(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        abort_unless(isset($this->ibanResult['iban']), 422);
        $this->data['creditor']['iban'] = $this->ibanResult['iban'];
        $this->data['creditor']['bic'] = $this->ibanResult['bic'];
        $this->ibanResult = [];
        $this->ibanCheck = '';
        $this->dispatch('close-modal', id: 'iban-help');
        Notification::make()->title('IBAN und BIC in den Gläubigerentwurf übernommen.')->body('Zum dauerhaften Speichern bitte „Gläubiger speichern“ verwenden.')->success()->send();
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

    /** Entfernt Zugangseingaben vor jeder Antwort aus dem Livewire-Zustand. */
    public function startBankTest(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $login = $this->bankLogin;
        $pin = $this->bankPin;
        $this->bankLogin = $this->bankPin = '';
        $this->resetValidation();
        try {
            $this->bankState = app(FintsReadOnlyTest::class)->start($login, $pin);
            $this->bankChoice = '';
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'fintsTest' ? 'bankTest' : $field, $messages[0]);
            }
        } catch (RuntimeException) {
            $this->bankState = [];
            $this->addError('bankTest', 'Bankabfrage fehlgeschlagen. Bitte Zugangsdaten, FinTS-Freischaltung, Produktnummer und Handy-Verfahren prüfen. Kein automatischer Wiederholungsversuch.');
        }
    }

    /** Führt Auswahl oder manuelle Freigabeprüfung anhand des serverseitigen Dialogzustands fort. */
    public function continueBankTest(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $this->resetValidation('bankTest');
        try {
            $this->bankState = app(FintsReadOnlyTest::class)->advance($this->bankState['token'] ?? '', $this->bankChoice);
            $this->bankChoice = '';
        } catch (ValidationException $exception) {
            $this->addError('bankTest', $exception->errors()['bankTest'][0]);
        } catch (RuntimeException) {
            $this->bankState = [];
            $this->addError('bankTest', 'Die Bankabfrage konnte nicht abgeschlossen werden. Bitte den Status in SecureGo plus prüfen und bei Bedarf neu starten.');
        }
    }

    /** Verwirft lokale Zugangsdaten und Dialogfortsetzung ausdrücklich auf Benutzerwunsch. */
    public function cancelBankTest(): void
    {
        try {
            app(FintsReadOnlyTest::class)->cancel();
            $this->bankState = [];
            $this->bankPin = $this->bankLogin = $this->bankChoice = '';
            $this->resetValidation();
        } catch (ValidationException $exception) {
            $this->addError('bankTest', $exception->errors()['bankTest'][0]);
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
