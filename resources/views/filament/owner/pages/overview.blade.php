<x-filament-panels::page>
    <section class="sd-intro">
        <div><span class="sd-eyebrow">DEIN BETRIEB</span><h2>Alles beginnt mit einem guten Überblick.</h2><p>Deine Standorte an einem Ort.</p></div>
        <span class="sd-count">{{ $stations->count() }} {{ $stations->count() === 1 ? 'Tankstelle' : 'Tankstellen' }}</span>
    </section>
    <section class="sd-surface" aria-labelledby="station-list-title">
        <header><h2 id="station-list-title">Standorte</h2><span>Dein Unternehmen</span></header>
        @forelse ($stations as $station)
            <article class="sd-station-row">
                <div class="sd-station-icon" aria-hidden="true">S</div>
                <div><h3>{{ $station->name }}</h3><p>{{ $station->street }} · {{ $station->postal_code }} {{ $station->city }}</p></div>
                <span class="sd-badge">{{ ['trial' => 'Testphase', 'active' => 'Aktiv', 'payment_overdue' => 'Zahlung offen', 'cancelled' => 'Gekündigt', 'archived' => 'Archiviert'][$station->lifecycle_status] ?? $station->lifecycle_status }}</span>
            </article>
        @empty
            <p class="sd-empty">Es sind noch keine Tankstellen vorhanden.</p>
        @endforelse
    </section>
</x-filament-panels::page>
