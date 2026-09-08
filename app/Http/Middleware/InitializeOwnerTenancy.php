<?php

namespace App\Http\Middleware;

use App\Models\Owner;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Prüft Owner-Zugriff und umschließt normale Panelanfragen mit dem zugehörigen Mandantenkontext. */
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
        $status = $owner->tenant()->value('provisioning_status');
        if ($status !== 'ready') {
            return response()->view('onboarding-pending', ['status' => $status], 202);
        }

        // Livewires persistente Middleware läuft in einer vorgelagerten Prüf-Pipeline.
        // Fachzugriffe innerhalb von Aktionen und Rendern benötigen deshalb zusätzlich
        // einen begrenzten forOwnerIfNeeded-Kontext; hier keinen globalen Kontext offen lassen.
        return app(TenantContext::class)->forOwner($owner, fn () => $next($request));
    }
}
