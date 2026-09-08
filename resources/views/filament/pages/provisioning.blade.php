<x-filament-panels::page>
    <section class="sd-intro"><div><span class="sd-eyebrow">PLATTFORM</span><h2>Ein verlässlicher Start für jeden Betreiber.</h2><p>Bereitstellung und letzte Verarbeitungsläufe.</p></div></section>
    <section class="sd-surface" aria-labelledby="tenants-title">
        <header><h2 id="tenants-title">Mandanten</h2><span>Zuletzt registriert</span></header>
        @forelse ($tenants as $tenant)
            <article class="sd-station-row">
                <div class="sd-station-icon" aria-hidden="true">M</div><div><h3>{{ $tenant->company_name }}</h3><p>{{ $tenant->created_at->format('d.m.Y') }}</p></div>
                <span class="sd-badge">{{ ['pending' => 'Ausstehend', 'provisioning' => 'Wird eingerichtet', 'ready' => 'Bereit', 'failed' => 'Fehlgeschlagen'][$tenant->provisioning_status] ?? $tenant->provisioning_status }}</span>
                @if ($tenant->provisioning_status === 'failed')
                    <x-filament::button wire:click="retry('{{ $tenant->id }}')" wire:loading.attr="disabled">Erneut versuchen</x-filament::button>
                @endif
            </article>
        @empty
            <p class="sd-empty">Noch keine Mandanten registriert.</p>
        @endforelse
    </section>
    <section class="sd-surface" aria-labelledby="runs-title">
        <header><h2 id="runs-title">Letzte Bereitstellungen</h2><span>Maximal 20 Einträge</span></header>
        <div class="sd-table-wrap"><table><thead><tr><th scope="col">Beginn</th><th scope="col">Status</th><th scope="col">Schritt</th><th scope="col">Fehlercode</th></tr></thead><tbody>
        @forelse ($runs as $run)
            <tr><td>{{ $run->started_at }}</td><td>{{ $run->status }}</td><td>{{ $run->step }}</td><td>{{ $run->error_code ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="4">Bisher keine Verarbeitungsläufe.</td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</x-filament-panels::page>
