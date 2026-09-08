<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Ein Vertrags-Owner pro Mandant; zentraler Login ist von seinem Mitarbeiterdatensatz getrennt. */
class Owner extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use CentralConnection, HasFactory, Notifiable;

    protected $guarded = ['*'];

    protected $hidden = ['password', 'remember_token'];

    /** Verhindert Klartextpasswörter und normalisiert den Bestätigungszeitpunkt. */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'immutable_datetime'];
    }

    /** Liefert ausschließlich die persistierte, nicht aus Requests abgeleitete Zuordnung. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Owner-Identitäten dürfen niemals das Plattformpanel betreten. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'owner';
    }
}
