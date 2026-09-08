<?php

namespace App\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Kurzlebiger, verschlüsselter Bankdialog, gebunden an Admin und aktuelle Anwendungssitzung. */
class FintsReadOnlyTest
{
    /** Beginnt mit expliziten Zugangsdaten; weder PIN noch VR-NetKey werden als Einstellung gespeichert. */
    public function start(string $login, #[\SensitiveParameter] string $pin): array
    {
        $admin = app(PlatformSettingsStore::class)->authorize();
        $input = Validator::make(['bankLogin' => trim($login), 'bankPin' => $pin], [
            'bankLogin' => ['required', 'string', 'max:100'], 'bankPin' => ['required', 'string', 'max:100'],
        ], ['required' => 'Bitte dieses Feld ausfüllen.', 'max' => 'Die Eingabe ist zu lang.'])->validate();
        $settings = DB::connection('central')->table('platform_fints_settings')->first();
        if (! $settings) {
            throw ValidationException::withMessages(['bankTest' => 'Bitte zuerst die FinTS-Einstellungen speichern.']);
        }
        app(TestFintsConnection::class)->endpoint($settings->endpoint);
        $cache = Cache::store('database');
        $key = $this->key();
        $lock = $cache->lock($key.':lock', 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['bankTest' => 'Ein Bankdialog wird bereits verarbeitet.']);
        }
        try {
            if ($cache->has($key)) {
                throw ValidationException::withMessages(['bankTest' => 'Bitte den laufenden Test zuerst beenden.']);
            }
            if (! $cache->add('fints-login-rate:'.$admin->id, true, 60)) {
                throw ValidationException::withMessages(['bankTest' => 'Bitte vor einer neuen Bankanmeldung eine Minute warten.']);
            }

            return $this->execute([
                'phase' => 'start', 'settings' => ['endpoint' => $settings->endpoint, 'bank_code' => $settings->bank_code, 'product_id' => $settings->product_id],
                'login' => $input['bankLogin'], 'pin' => $input['bankPin'], 'expires' => time() + 600,
            ]);
        } finally {
            $lock->release();
        }
    }

    /** Verbraucht jeden Zustandsstand einmal; doppelte Requests können denselben Bankdialog nicht wiederholen. */
    public function advance(string $token, string $choice = ''): array
    {
        app(PlatformSettingsStore::class)->authorize();
        $cache = Cache::store('database');
        $key = $this->key();
        $lock = $cache->lock($key.':lock', 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['bankTest' => 'Ein Bankdialog wird bereits verarbeitet.']);
        }
        try {
            $encrypted = $cache->get($key);
            if (! $encrypted) {
                throw ValidationException::withMessages(['bankTest' => 'Der Test ist abgelaufen oder beendet. Bitte neu starten.']);
            }
            $state = Crypt::decrypt($encrypted);
            if (! hash_equals($state['token'], $token)) {
                throw ValidationException::withMessages(['bankTest' => 'Dieser Dialogstand ist veraltet. Bitte den Test beenden und neu starten.']);
            }
            if ($state['phase'] === 'waiting' && time() < $state['next_check']) {
                throw ValidationException::withMessages(['bankTest' => 'Die Bank verlangt noch eine Wartezeit. Bitte in einigen Sekunden erneut prüfen.']);
            }
            if ($state['expires'] <= time() || ($state['phase'] === 'waiting' && $state['max_checks'] > 0 && $state['checks'] >= $state['max_checks'])) {
                $cache->forget($key);
                throw ValidationException::withMessages(['bankTest' => 'Zeit- oder Versuchslimit erreicht. Bitte einen neuen Test starten.']);
            }
            $cache->forget($key);

            return $this->execute($state, $choice);
        } finally {
            $lock->release();
        }
    }

    /** Entfernt die lokale Fortsetzung; eine bereits angezeigte Bankfreigabe kann auf dem Handy noch sichtbar bleiben. */
    public function cancel(): void
    {
        app(PlatformSettingsStore::class)->authorize();
        $cache = Cache::store('database');
        $lock = $cache->lock($this->key().':lock', 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['bankTest' => 'Bitte die laufende Bankantwort abwarten.']);
        }
        try {
            $cache->forget($this->key());
        } finally {
            $lock->release();
        }
    }

    /** Filtert Browserdaten strikt; Fehlerdialoge aus der Bankbibliothek gelangen nicht in UI oder Logs. */
    private function execute(array $state, string $choice = ''): array
    {
        try {
            $result = app(FintsReadOnlyAdapter::class)->advance($state, $choice);
            if ($result['phase'] !== 'done') {
                $result['token'] = (string) Str::uuid();
                Cache::store('database')->put($this->key(), Crypt::encrypt($result), max(1, $result['expires'] - time()));
            }
        } catch (Throwable) {
            Cache::store('database')->forget($this->key());
            $this->audit('failed');
            throw new RuntimeException('Bankabfrage fehlgeschlagen. Bitte Zugangsdaten, FinTS-Freischaltung und Produktnummer prüfen. Es wird nicht automatisch erneut angemeldet.');
        }
        $this->audit($result['phase']);

        return array_intersect_key($result, array_flip(['phase', 'token', 'options', 'challenge', 'accounts', 'next_check']));
    }

    /** Der Schlüssel enthält weder Bankzugang noch PIN, sondern die aktuelle Sitzungsbindung. */
    private function key(): string
    {
        return 'fints-read-test:'.auth('admin')->id().':'.hash('sha256', session()->getId());
    }

    /** Nur technische Übergänge protokollieren; Konten, Challenges und Zugangsdaten bleiben ausgeschlossen. */
    private function audit(string $phase): void
    {
        DB::connection('central')->table('audit_events')->insert([
            'actor_type' => 'super_admin', 'actor_id' => (string) auth('admin')->id(),
            'action' => 'platform_fints.read_test.'.$phase, 'occurred_at' => now(),
        ]);
    }
}
