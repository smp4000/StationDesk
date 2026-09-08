<?php

namespace App\Filament\Owner\Auth;

use App\Models\Owner;
use App\Onboarding\SendPasswordResetMail;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequest;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/** Passwortwiederherstellung ausschließlich für Owner, mit neutraler Antwort und gespeichertem Plattform-Absender. */
class RequestPasswordReset extends BaseRequest
{
    /** Der Owner-Broker schützt Ablauf, Einmaligkeit und Wiederholungen; Tokens gelangen weder in Queue noch Audit. */
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }
        $data = $this->form->getState();
        try {
            Password::broker('owners')->sendResetLink(
                ['email' => Str::lower(trim($data['email']))],
                function (Owner $owner, #[SensitiveParameter] string $token): void {
                    app(SendPasswordResetMail::class)->send($owner, $token);
                },
            );
        } catch (RuntimeException) {
            // Auch bei SMTP-Problemen keine Existenz eines bestimmten Kontos offenlegen.
            // Der Versanddienst schreibt einen bereinigten Fehler ins zentrale Audit.
        }
        Notification::make()->title('Anfrage verarbeitet')
            ->body('Falls ein passendes Konto vorhanden ist und der Versand möglich war, erhältst du eine E-Mail. Prüfe auch den Spamordner. Bei ausbleibender Nachricht bitte später erneut versuchen oder die Plattformverwaltung kontaktieren.')
            ->success()->send();
        $this->form->fill();
    }
}
