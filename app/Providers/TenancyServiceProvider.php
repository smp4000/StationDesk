<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

/** Aktiviert den Paketkontext ohne unbestätigte Registrierungen automatisch zu provisionieren. */
class TenancyServiceProvider extends ServiceProvider
{
    /** Registriert die symmetrischen Initialisierungs- und Bereinigungslistener. */
    public function boot(): void
    {
        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
    }
}
