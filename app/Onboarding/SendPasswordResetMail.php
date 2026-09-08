<?php

namespace App\Onboarding;

use App\Models\Owner;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Passwort-Reset über den zentral gepflegten SMTP-Absender, ohne Token in Queue oder Protokollen. */
class SendPasswordResetMail
{
    /** Bindet den Reset an die persistierte Owner-Adresse und ausschließlich an das Owner-Panel. */
    public function send(Owner $owner, #[SensitiveParameter] string $token): void
    {
        $owner = Owner::query()->findOrFail($owner->id);
        $audit = ['tenant_id' => $owner->tenant_id, 'actor_type' => 'system',
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
            $sent = $mailer->send(['html' => 'emails.reset-owner-password', 'text' => 'emails.reset-owner-password-text'], [
                'url' => Filament::getPanel('owner')->getResetPasswordUrl($token, $owner),
                'minutes' => (int) config('auth.passwords.owners.expire'), 'firstName' => $owner->first_name,
            ], function (Message $message) use ($settings, $owner): void {
                $message->from($settings->from_address, $settings->from_name)->to($owner->email)->subject('StationDeck – Passwort zurücksetzen');
            });
            if ($sent === null) {
                throw new RuntimeException('Versand unterbrochen.');
            }
        } catch (Throwable) {
            DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'owner.password_reset_mail_failed']);
            throw new RuntimeException('Passwort-E-Mail-Versand konnte nicht bestätigt werden.');
        }
        DB::connection('central')->table('audit_events')->insert($audit + ['action' => 'owner.password_reset_mail_accepted']);
    }
}
