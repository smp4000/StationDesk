<?php

namespace App\Filament\Owner\Auth;

use Closure;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseReset;
use Filament\Schemas\Components\Component;

/** Behält Filaments geprüften Tokenablauf bei und verwendet die Passwortgrenzen der Registrierung. */
class ResetPassword extends BaseReset
{
    /** Verhindert kürzere Reset-Passwörter und mehrdeutige Kürzungen an Bcrypts Byte-Grenze. */
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->minLength(12)->maxLength(72)->rule(
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Das Passwort ist zu lang. Bitte bei Sonderzeichen ein kürzeres Passwort wählen.');
                }
            },
        );
    }

    /** Entfernt Passwortfelder auch nach Validierungsfehlern aus der Livewire-Antwort. */
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            return parent::resetPassword();
        } finally {
            $this->password = $this->passwordConfirmation = '';
        }
    }
}
