<?php

namespace App\Settings;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Expliziter SMTP-Test mit gespeichertem Plattformprofil, ohne Änderung des allgemeinen Mailers. */
class SendPlatformTestMail
{
    /** Prüft Admin/MFA und Empfänger, begrenzt Wiederholungen und protokolliert ohne Zugangsdaten. */
    public function send(string $recipient): void
    {
        $admin = app(PlatformSettingsStore::class)->authorize();
        $recipient = Validator::make(['testRecipient' => trim($recipient)], [
            'testRecipient' => ['required', 'email', 'max:255'],
        ], [
            'required' => 'Bitte eine Empfängeradresse eingeben.',
            'email' => 'Bitte eine gültige Empfängeradresse eingeben.',
            'max' => 'Die Empfängeradresse ist zu lang.',
        ])->validate()['testRecipient'];
        $settings = DB::connection('central')->table('platform_smtp_settings')->first();
        if (! $settings) {
            throw ValidationException::withMessages(['testRecipient' => 'Bitte zuerst die SMTP-Einstellungen speichern.']);
        }
        if (! Cache::store('database')->add('platform-test-mail:'.$admin->id, true, 30)) {
            throw ValidationException::withMessages(['testRecipient' => 'Bitte vor der nächsten Testmail 30 Sekunden warten.']);
        }
        $audit = [
            'actor_type' => 'super_admin', 'actor_id' => (string) $admin->id,
            'subject_id' => (string) $settings->id, 'occurred_at' => now(),
        ];
        DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'platform_smtp.test_requested']);
        try {
            $mailer = app(MailManager::class)->build([
                'transport' => 'smtp', 'scheme' => $settings->encryption === 'smtps' ? 'smtps' : 'smtp',
                'host' => $settings->host, 'port' => $settings->port,
                'username' => $settings->username, 'password' => Crypt::decryptString($settings->password),
                'require_tls' => true, 'timeout' => 15,
            ]);
            $sent = $mailer->raw("Dies ist eine Testmail von StationDeck.\n\nDer Versand wurde im Super-Admin-Bereich ausdrücklich ausgelöst.\n", function (Message $message) use ($settings, $recipient): void {
                $message->from($settings->from_address, $settings->from_name)
                    ->to($recipient)->subject('StationDeck – SMTP-Test');
            });
            if ($sent === null) {
                throw new RuntimeException('Mailversand wurde unterbrochen.');
            }
        } catch (Throwable) {
            DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'platform_smtp.test_failed']);
            // SMTP-Exceptions können Authentifizierungsdialoge enthalten; niemals weiterreichen oder protokollieren.
            throw new RuntimeException('SMTP-Test nicht bestätigt. Bitte Server, Port, TLS und Zugangsdaten prüfen. Die Nachricht kann bei einem Verbindungsabbruch bereits angenommen worden sein.');
        }
        DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'platform_smtp.test_accepted']);
    }
}
