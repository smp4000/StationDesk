<?php

namespace App\Providers\Filament;

use App\Filament\Owner\Auth\Register;
use App\Filament\Owner\Auth\VerifyEmailPrompt;
use App\Http\Middleware\InitializeOwnerTenancy;
use App\Onboarding\RegisterOwner;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Gemeinsamer Owner-Zugang mit persistentem Tenant-Schutz für Livewire-Anfragen. */
class OwnerPanelProvider extends PanelProvider
{
    /** Verwendet das eigene Theme und getrennte zentrale Authentifizierung. */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('owner')
            ->login()
            ->registration(RegisterOwner::available() ? Register::class : null)
            ->passwordReset()
            ->emailVerification(VerifyEmailPrompt::class)
            ->authGuard('web')
            ->authPasswordBroker('owners')
            ->brandName('StationDeck')
            ->font('Segoe UI', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Teal,
            ])
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\Filament\Owner\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\Filament\Owner\Pages')
            ->pages([
            ])
            ->discoverWidgets(in: app_path('Filament/Owner/Widgets'), for: 'App\Filament\Owner\Widgets')
            ->widgets([
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                InitializeOwnerTenancy::class,
            ], isPersistent: true);
    }
}
