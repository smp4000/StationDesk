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
    /**
     * Begrenzt auch Livewire-Aktionen auf einen frisch geprüften Owner-Kontext.
     * Persistente Middleware endet vor der Aktion. Ein bereits vorhandener Kontext
     * darf nur bei identischer persistierter Zuordnung wiederverwendet werden.
     */
    public function forOwnerIfNeeded(Owner $owner, Closure $callback): mixed
    {
        if (! tenancy()->initialized) {
            return $this->forOwner($owner, $callback);
        }
        $owner = Owner::query()->findOrFail($owner->getAuthIdentifier());
        if (! $owner->hasVerifiedEmail() || $owner->tenant_id !== (string) tenant('id')
            || $owner->tenant()->value('provisioning_status') !== 'ready') {
            throw new AuthorizationException('Passender bestätigter Owner-Kontext erforderlich.');
        }

        return $callback();
    }

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
