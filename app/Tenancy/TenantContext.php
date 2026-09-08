<?php

namespace App\Tenancy;

use App\Models\Owner;
use App\Models\Tenant;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/** Begrenzter Ausführungskontext mit garantierter Bereinigung nach erfolgreichen und fehlgeschlagenen Aktionen. */
class TenantContext
{
    /** Initialisiert nur den bestätigten Owner-Mandanten; fremde oder unfertige Mandanten bleiben gesperrt. */
    public function forOwner(Owner $owner, Closure $callback): mixed
    {
        if ($owner->exists) {
            $owner = Owner::query()->findOrFail($owner->getAuthIdentifier());
        }
        if (! $owner->hasVerifiedEmail() || ! $owner->exists) {
            throw new AuthorizationException('Bestätigte Owner-Identität erforderlich.');
        }
        $tenant = $owner->tenant()->firstOrFail();
        if ($tenant->provisioning_status !== 'ready') {
            throw new AuthorizationException('Der Mandant ist noch nicht bereit.');
        }

        return $this->run($tenant, $callback);
    }

    /** Interner Worker-Einstieg: keine Übernahme einer ungeprüften Tenant-ID aus einer HTTP-Anfrage. */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        if (tenancy()->initialized) {
            throw new LogicException('Verschachtelte Mandantenkontexte sind nicht erlaubt.');
        }
        try {
            tenancy()->initialize($tenant);

            return $callback();
        } finally {
            tenancy()->end();
        }
    }
}
