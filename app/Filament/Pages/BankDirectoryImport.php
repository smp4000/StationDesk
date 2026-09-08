<?php

namespace App\Filament\Pages;

use App\Settings\BankDirectory;
use App\Settings\PlatformSettingsStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

/** Admin-Oberfläche für geprüfte Bundesbank-Vollimporte mit zeitlich gültiger Bestandsauswahl. */
class BankDirectoryImport extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.bank-directory-import';

    protected static ?string $title = 'Bundesbank-Bankenstamm';

    protected static ?string $navigationLabel = 'Bankenstamm / CSV-Import';

    public $csvFile = null;

    public string $validFrom = '';

    public string $validUntil = '';

    /** Gleicher Rollen- und MFA-Schutz wie die übrigen Plattform-Einstellungen. */
    public static function canAccess(): bool
    {
        return PlatformSettings::canAccess();
    }

    /** Prüft jeden Aufruf erneut; nur vollständige erfolgreiche Importe erscheinen als neuer Stand. */
    public function importCsv(): void
    {
        $admin = app(PlatformSettingsStore::class)->authorize();
        $this->validate([
            'csvFile' => ['required', 'file', 'max:10240', 'extensions:csv'],
            'validFrom' => ['required', 'date_format:Y-m-d'],
            'validUntil' => ['required', 'date_format:Y-m-d', 'after_or_equal:validFrom'],
        ], [
            'required' => 'Bitte dieses Feld ausfüllen.', 'file' => 'Bitte eine Datei auswählen.',
            'max' => 'Die Datei darf höchstens 10 MB groß sein.', 'extensions' => 'Bitte eine ungepackte CSV-Datei auswählen.',
            'date_format' => 'Bitte ein gültiges Datum eingeben.', 'after_or_equal' => 'Das Enddatum darf nicht vor dem Beginn liegen.',
        ]);
        try {
            $id = app(BankDirectory::class)->import($this->csvFile->getRealPath(), $this->validFrom, $this->validUntil, $admin);
            $count = DB::connection('central')->table('bank_directory_imports')->where('id', $id)->value('row_count');
            Notification::make()->title('Bankenstamm verfügbar')->body('Import #'.$id.' mit '.number_format($count, 0, ',', '.').' Datensätzen. Bereits vorhandene identische Dateien werden nicht doppelt importiert.')->success()->send();
        } catch (Throwable $exception) {
            $this->addError('csvFile', get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Import fehlgeschlagen. Bitte CSV und Datenbankverbindung prüfen. Der bisherige Bestand bleibt erhalten.');
        } finally {
            $this->csvFile->delete();
            $this->csvFile = null;
        }
    }

    /** Liefert den tatsächlich verwendeten Stand und die letzten Importe ohne Dateiinhalte. */
    protected function getViewData(): array
    {
        app(PlatformSettingsStore::class)->authorize();
        $today = now('Europe/Berlin')->toDateString();
        $query = DB::connection('central')->table('bank_directory_imports');

        return [
            'today' => $today,
            'active' => (clone $query)->where('valid_from', '<=', $today)->where('valid_until', '>=', $today)->orderByDesc('valid_from')->orderByDesc('id')->first(),
            'imports' => $query->orderByDesc('id')->limit(20)->get(),
        ];
    }
}
