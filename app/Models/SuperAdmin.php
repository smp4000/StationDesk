<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Separate Plattformidentität; TOTP-Geheimnis wird durch Filaments Trait verschlüsselt. */
class SuperAdmin extends Authenticatable implements FilamentUser, HasAppAuthentication
{
    use CentralConnection, HasFactory, InteractsWithAppAuthentication, Notifiable;

    protected $guarded = ['*'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret'];

    /** Hashing gilt auch für administrative Kontoanlage ohne öffentliche Registrierung. */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** Die MFA-Pflicht wird zusätzlich durch das Plattformpanel erzwungen. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }
}
