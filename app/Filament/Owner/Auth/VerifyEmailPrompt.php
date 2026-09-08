<?php

namespace App\Filament\Owner\Auth;

use App\Models\Owner;
use App\Onboarding\SendVerificationMail;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Bestätigung und begrenzter Neuversand verwenden denselben gespeicherten Plattform-Absender. */
class VerifyEmailPrompt extends EmailVerificationPrompt
{
    /** Filament begrenzt den Neuversand; Versandfehler verhindern eine fälschliche Erfolgsmeldung. */
    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        abort_unless($user instanceof Owner, 403);
        try {
            app(SendVerificationMail::class)->send($user);
            $this->resetValidation('verificationMail');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['verificationMail' => $exception->getMessage()]);
        }
    }

    /** Verspricht keine Zustellung; zeigt bei SMTP-Problemen den sicheren Fehlertext neben dem Neuversand. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Bitte öffne den Bestätigungslink aus deiner E-Mail. Deine Tankstelle wird erst nach dieser Bestätigung eingerichtet. Prüfe auch deinen Spamordner.'),
            Text::make(fn () => $this->getErrorBag()->first('verificationMail') ?: session('verification_mail_error', ''))->color('danger'),
            Text::make(new HtmlString($this->resendNotificationAction->toHtml())),
        ]);
    }
}
