<?php

namespace App\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Prüft ausschließlich HTTPS-Erreichbarkeit des freigegebenen Bankendpunkts, ohne Bankanmeldung. */
class TestFintsConnection
{
    /** Die feste Bankadresse verhindert Zugriffe auf interne Dienste über manipulierte Einstellungen. */
    public function endpoint(string $endpoint): string
    {
        if ($endpoint !== 'https://fints2.atruvia.de/cgi-bin/hbciservlet') {
            throw ValidationException::withMessages(['fintsTest' => 'Für den Pilot ist ausschließlich der FinTS-Endpunkt https://fints2.atruvia.de/cgi-bin/hbciservlet freigegeben.']);
        }

        return $endpoint;
    }

    /** Liefert den HTTP-Status; dieser ist ausdrücklich kein Nachweis eines erfolgreichen FinTS-Dialogs. */
    public function run(): int
    {
        $admin = app(PlatformSettingsStore::class)->authorize();
        $settings = DB::connection('central')->table('platform_fints_settings')->first();
        if (! $settings) {
            throw ValidationException::withMessages(['fintsTest' => 'Bitte zuerst die FinTS-Einstellungen speichern.']);
        }
        $endpoint = $this->endpoint($settings->endpoint);
        if (! Cache::store('database')->add('fints-connection-test:'.$admin->id, true, 30)) {
            throw ValidationException::withMessages(['fintsTest' => 'Bitte vor dem nächsten Verbindungstest 30 Sekunden warten.']);
        }
        $audit = ['actor_type' => 'super_admin', 'actor_id' => (string) $admin->id,
            'subject_id' => (string) $settings->id, 'occurred_at' => now()];
        try {
            $response = Http::withOptions(['verify' => true, 'allow_redirects' => false])
                ->connectTimeout(5)->timeout(15)->head($endpoint);
        } catch (Throwable) {
            DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'platform_fints.connection_failed']);
            throw new RuntimeException('HTTPS-Verbindung nicht bestätigt. Bitte Netzwerk, Bankadresse und Zertifikatsprüfung kontrollieren.');
        }
        DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'platform_fints.endpoint_reached']);

        return $response->status();
    }
}
