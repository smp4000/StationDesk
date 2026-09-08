<?php

namespace App\Onboarding;

use App\Models\Owner;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Bestätigungsversand über den gespeicherten Plattform-Absender; Fehler und Audit enthalten keine Mailinhalte oder SMTP-Geheimnisse. */
class SendVerificationMail
{
    /** Versendet ausschließlich an die persistierte Owner-Adresse; der signierte Link läuft nach Filaments Frist ab. */
    public function send(Owner $owner): void
    {
        $owner = Owner::query()->findOrFail($owner->id);
        if ($owner->hasVerifiedEmail()) {
            return;
        }
        $audit = ['tenant_id' => $owner->tenant_id, 'actor_type' => 'owner', 'actor_id' => (string) $owner->id,
            'subject_id' => (string) $owner->id, 'occurred_at' => now()];
        try {
            $settings = DB::connection('central')->table('platform_smtp_settings')->first();
            if (! $settings) {
                throw new RuntimeException('Plattform-Absender fehlt.');
            }
            $mailer = app(MailManager::class)->build([
                'transport' => 'smtp', 'scheme' => $settings->encryption === 'smtps' ? 'smtps' : 'smtp',
                'host' => $settings->host, 'port' => $settings->port, 'username' => $settings->username,
                'password' => Crypt::decryptString($settings->password), 'require_tls' => true, 'timeout' => 15,
            ]);
            $url = Filament::getPanel('owner')->getVerifyEmailUrl($owner);
            $minutes = (int) config('auth.verification.expire', 60);
            // Tabellenlayout und Inline-Stile unterstützen auch klassische Outlook-Versionen;
            // eine eigene Textalternative hält den signierten Link ohne HTML-Escaping kopierbar.
            $sent = $mailer->send(['html' => 'emails.verify-owner', 'text' => 'emails.verify-owner-text'], [
                'url' => $url, 'minutes' => $minutes, 'firstName' => $owner->first_name,
            ], function (Message $message) use ($settings, $owner): void {
                $message->from($settings->from_address, $settings->from_name)->to($owner->email)->subject('StationDeck – E-Mail-Adresse bestätigen');
            });
            if ($sent === null) {
                throw new RuntimeException('Versand unterbrochen.');
            }
        } catch (Throwable) {
            DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'registration.verification_mail_failed']);
            throw new RuntimeException('Der E-Mail-Versand konnte nicht bestätigt werden. Dein Konto bleibt angelegt. Bitte später erneut senden; bei wiederholten Problemen die SMTP-Einstellungen in der Plattformverwaltung prüfen.');
        }
        DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'registration.verification_mail_accepted']);
    }
}
