<?php

namespace App\Listeners;

use App\Jobs\ProvisionTenant;
use App\Models\Owner;
use Illuminate\Auth\Events\Verified;

/** E-Mail-Bestätigung startet ausschließlich die Bereitstellung einer zugehörigen Owner-Registrierung. */
class QueueVerifiedTenant
{
    /** Super-Admin- und sonstige Verifikationsereignisse dürfen keine Mandanten erstellen. */
    public function handle(Verified $event): void
    {
        if ($event->user instanceof Owner) {
            ProvisionTenant::dispatch((string) $event->user->tenant_id)->afterCommit();
        }
    }
}
