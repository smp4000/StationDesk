<?php

namespace App\Settings;

use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Öffentlicher Bankenstamm: strikter CSV-Vollimport und zeitlich gültige BLZ-Zuordnung. */
class BankDirectory
{
    /** Liest die öffentliche 13-spaltige Bundesbank-CSV atomar; bestehende Fassungen bleiben erhalten. */
    public function import(string $path, string $from, string $until, ?SuperAdmin $actor = null): int
    {
        Validator::make(['from' => $from, 'until' => $until], [
            'from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            throw new RuntimeException('CSV fehlt oder ist größer als 10 MB.');
        }
        $raw = file_get_contents($path);
        $hash = hash('sha256', $raw);
        $text = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $text));
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ';', '"', '');
            if ($header !== ['Bankleitzahl', 'Merkmal', 'Bezeichnung', 'PLZ', 'Ort', 'Kurzbezeichnung', 'PAN', 'BIC', 'Prüfzifferberechnungsmethode', 'Datensatznummer', 'Änderungskennzeichen', 'Bankleitzahllöschung', 'Nachfolge-Bankleitzahl']) {
                throw new RuntimeException('Die Kopfzeile entspricht nicht der öffentlichen Bundesbank-CSV mit 13 Spalten.');
            }
            $rows = [];
            $seen = $main = [];
            $line = 1;
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                $valid = count($row) === 13;
                foreach ([0 => '/^\d{8}$/D', 1 => '/^[12]$/D', 3 => '/^\d{5}$/D', 6 => '/^(?:\d{5})?$/D',
                    7 => '/^(?:[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)?$/D', 8 => '/^[A-Z0-9]{2}$/D',
                    9 => '/^\d{6}$/D', 10 => '/^[AMUD]$/D', 11 => '/^[01]$/D', 12 => '/^\d{8}$/D'] as $index => $pattern) {
                    $valid = $valid && isset($row[$index]) && preg_match($pattern, $row[$index]);
                }
                foreach ([2, 4, 5] as $index) {
                    $valid = $valid && isset($row[$index]) && $row[$index] !== '' && mb_strlen($row[$index]) <= 255;
                }
                if (! $valid || isset($seen[$row[9]]) || ($row[1] === '1' && isset($main[$row[0]]))) {
                    throw new RuntimeException('Ungültiger oder doppelter Datensatz in CSV-Zeile '.$line.'.');
                }
                $seen[$row[9]] = true;
                if ($row[1] === '1') {
                    $main[$row[0]] = true;
                }
                $rows[] = array_combine(['bank_code', 'record_type', 'name', 'postal_code', 'city', 'short_name', 'pan', 'bic', 'check_method', 'record_number', 'change_code', 'deletion_planned', 'successor_bank_code'], $row);
            }
        } finally {
            fclose($stream);
        }
        if (! $rows || ! $main) {
            throw new RuntimeException('Die CSV enthält keine führenden Bankdatensätze.');
        }

        return DB::connection('central')->transaction(function () use ($hash, $from, $until, $rows, $actor): int {
            $db = DB::connection('central');
            $db->table('platform_settings_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $existing = $db->table('bank_directory_imports')->where(['sha256' => $hash, 'valid_from' => $from, 'valid_until' => $until])->first();
            if ($existing) {
                return $existing->id;
            }
            $id = $db->table('bank_directory_imports')->insertGetId([
                'sha256' => $hash, 'valid_from' => $from, 'valid_until' => $until, 'row_count' => count($rows), 'created_at' => now(),
            ]);
            foreach (array_chunk($rows, 250) as $chunk) {
                $db->table('bank_directory_entries')->insert(array_map(fn (array $row): array => $row + ['import_id' => $id], $chunk));
            }
            $db->table('audit_events')->insert(['actor_type' => $actor ? 'super_admin' : 'system', 'actor_id' => $actor ? (string) $actor->id : null,
                'action' => 'bank_directory.imported', 'subject_id' => (string) $id, 'occurred_at' => now()]);

            return $id;
        });
    }

    /** Nutzt nur aktuell gültige Vollstände und aktive führende Datensätze; keine automatische BLZ-Umschreibung. */
    public function find(string $bankCode): array
    {
        app(PlatformSettingsStore::class)->authorize();
        Validator::make(['bankCode' => $bankCode], ['bankCode' => ['required', 'regex:/^\d{8}$/D']], [
            'required' => 'Bitte eine Bankleitzahl eingeben.', 'regex' => 'Die Bankleitzahl muss acht Ziffern enthalten.',
        ])->validate();
        $date = now('Europe/Berlin')->toDateString();
        $db = DB::connection('central');
        $import = $db->table('bank_directory_imports')->where('valid_from', '<=', $date)->where('valid_until', '>=', $date)->orderByDesc('valid_from')->orderByDesc('id')->first();
        if (! $import) {
            throw ValidationException::withMessages(['bankCode' => 'Kein aktuell gültiger Bundesbank-Import vorhanden.']);
        }
        $bank = $db->table('bank_directory_entries')->where(['import_id' => $import->id, 'bank_code' => $bankCode, 'record_type' => '1'])->where('change_code', '!=', 'D')->first();
        if (! $bank) {
            throw ValidationException::withMessages(['bankCode' => 'Keine aktive Bank mit dieser Bankleitzahl gefunden.']);
        }

        return ['name' => $bank->name, 'city' => $bank->city, 'bank_code' => $bank->bank_code, 'bic' => $bank->bic,
            'deletion_planned' => (bool) $bank->deletion_planned, 'valid_until' => $import->valid_until];
    }

    /** Vom Nutzer gewünschte Standardrechnung; unbekannte institutsindividuelle Regeln werden nicht vorgetäuscht. */
    public function propose(string $bankCode, string $accountNumber): array
    {
        $bank = $this->find($bankCode);
        Validator::make(['accountNumber' => $accountNumber], ['accountNumber' => ['required', 'regex:/^\d{1,10}$/D', 'not_regex:/^0+$/D']], [
            'required' => 'Bitte die Kontonummer eingeben.', 'regex' => 'Bitte eine Kontonummer mit 1 bis 10 Ziffern eingeben.',
            'not_regex' => 'Die Kontonummer darf nicht nur aus Nullen bestehen.',
        ])->validate();
        $bban = $bankCode.str_pad($accountNumber, 10, '0', STR_PAD_LEFT);
        $check = str_pad((string) (98 - $this->remainder($bban.'131400')), 2, '0', STR_PAD_LEFT);

        return $bank + ['iban' => 'DE'.$check.$bban, 'kind' => 'proposal'];
    }

    /** Prüft deutsche IBAN-Struktur und Prüfsumme und ergänzt eine aktuelle Bankzuordnung, keine Kontoinhaberschaft. */
    public function check(string $iban): array
    {
        app(PlatformSettingsStore::class)->authorize();
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (! preg_match('/^DE\d{20}$/D', $iban) || $this->remainder(substr($iban, 4).'1314'.substr($iban, 2, 2)) !== 1) {
            throw ValidationException::withMessages(['ibanCheck' => 'Die deutsche IBAN benötigt 22 Zeichen und eine gültige Prüfsumme.']);
        }

        return $this->find(substr($iban, 4, 8)) + ['iban' => $iban, 'kind' => 'checked'];
    }

    /** Modulo-97 ohne Fließkommazahlen oder überlaufende große Integer. */
    private function remainder(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
