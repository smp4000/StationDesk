<?php

namespace App\Http\Middleware;

use App\Models\Owner;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Bindet normale und persistente Livewire-Panelanfragen an den authentifizierten Owner. */
class InitializeOwnerTenancy
{
    /** Authentifizierungsseiten bleiben zentral; Fachseiten laufen in einem begrenzten Tenant-Kontext. */
    public function handle(Request $request, Closure $next): Response
    {
        $owner = Auth::guard('web')->user();
        abort_unless($owner instanceof Owner, 403);
        if ($request->routeIs('filament.owner.auth.*')) {
            return $next($request);
        }
        if (! $owner->hasVerifiedEmail()) {
            return redirect()->route('filament.owner.auth.email-verification.prompt');
        }
        if ($owner->tenant()->value('provisioning_status') !== 'ready') {
            return response()->view('onboarding-pending', [], 202);
        }

        return app(TenantContext::class)->forOwner($owner, fn () => $next($request));
    }
}
