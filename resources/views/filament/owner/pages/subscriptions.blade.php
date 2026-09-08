<div class="sd-settings">
    @php($date = fn ($value) => \Carbon\CarbonImmutable::parse($value, 'UTC')->timezone('Europe/Berlin')->format('d.m.Y · H:i').' Uhr')
    <section class="sd-intro"><div><span class="sd-eyebrow">DEINE STATIONSABOS</span><h2>Preis und Laufzeit. Klar im Blick.</h2><p>Jede Tankstelle hat ihr eigenes Abo. Hier siehst du die gespeicherten Konditionen und den nächsten Endtermin.</p></div></section>
    @forelse ($subscriptions as $subscription)
        <section class="sd-surface" wire:key="subscription-{{ $subscription['id'] }}">
            <header><h2>{{ $stationNames->get($subscription['station_id'], 'Tankstelle') }}</h2><span class="sd-badge">{{ $subscription['status_label'] }}</span></header>
            <div class="sd-settings-form">
                @if ($subscription['is_test'])<p class="sd-settings-note">Lokales Testabo: Die Laufzeiten werden simuliert. Es entstehen keine Rechnungen oder Bankeinzüge.</p>@endif
                <dl class="sd-settings-grid">
                    <div><dt>Monatlicher Stationspreis</dt><dd><strong>{{ number_format(intdiv($subscription['gross_cents'], 100), 0, ',', '.') }},{{ str_pad((string) ($subscription['gross_cents'] % 100), 2, '0', STR_PAD_LEFT) }} € brutto</strong></dd><dd>Enthält {{ intdiv($subscription['tax_basis_points'], 100) }} % Umsatzsteuer · gespeicherter Preisstand</dd></div>
                    <div><dt>Ende der 30-Tage-Testphase</dt><dd><strong>{{ $date($subscription['trial_end']) }}</strong></dd></div>
                    @if (! $subscription['ended'])
                        <div><dt>{{ $subscription['in_trial'] ? 'Aktueller Trial' : 'Aktuelle Monatsperiode' }}</dt><dd>{{ $date($subscription['period_start']) }}<br>bis {{ $date($subscription['period_end']) }}</dd></div>
                    @endif
                    <div><dt>{{ $subscription['cancelled_at'] ? 'Bestätigtes Abo-Ende' : 'Bei Kündigung endet dieses Abo am' }}</dt><dd><strong>{{ $date($subscription['cancellation_end']) }}</strong></dd></div>
                </dl>
                @if ($subscription['cancelled_at'])
                    <p role="status">Kündigung erklärt am {{ $date($subscription['cancelled_at']) }}. {{ $subscription['ended'] ? 'Das Ende ist erreicht.' : 'Bis zum bestätigten Endtermin läuft das Abo weiter.' }} Es wird danach nicht verlängert.</p>
                    @if ($subscription['can_reactivate'])
                        <div><x-filament::button wire:click="prepareReactivation({{ $subscription['id'] }})" wire:loading.attr="disabled" aria-haspopup="dialog">{{ $subscription['ended'] ? 'Abo reaktivieren' : 'Kündigung zurückziehen' }}</x-filament::button></div>
                    @endif
                @elseif ($subscription['can_cancel'])
                    <p>{{ $subscription['in_trial'] ? 'Eine Kündigung während des Trials wird zum Trial-Ende wirksam.' : 'Eine Kündigung wird zum Ende der laufenden Monatsperiode wirksam.' }}</p>
                    <div><x-filament::button color="gray" wire:click="prepareCancellation({{ $subscription['id'] }})" wire:loading.attr="disabled" aria-haspopup="dialog">Testabo kündigen</x-filament::button></div>
                @endif
            </div>
        </section>
    @empty
        <section class="sd-surface"><p class="sd-empty">Für deine Tankstellen sind noch keine Abos vorhanden.</p></section>
    @endforelse
    <x-filament::modal id="cancel-subscription" width="lg" :close-by-clicking-away="false">
        <x-slot name="heading">Testabo kündigen</x-slot>
        @if ($cancellation)
            <p>Du kündigst das Testabo für <strong>{{ $cancellation['station'] }}</strong>.</p>
            <p>Es endet am <strong>{{ $date($cancellation['end']) }}</strong>. Bis dahin bleibt es nutzbar. Danach wird es nicht verlängert.</p>
            <p>Diese Aktion löscht keine Tankstellen- oder Mitarbeiterdaten.</p>
        @endif
        @error('cancellation')<p role="alert" class="sd-field-error">{{ $message }}</p>@enderror
        <x-slot name="footer">
            <div class="sd-settings-actions">
                <x-filament::button color="danger" wire:click="confirmCancellation" wire:loading.attr="disabled">Kündigung jetzt bestätigen</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'cancel-subscription' })">Zurück</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
    <x-filament::modal id="reactivate-subscription" width="lg" :close-by-clicking-away="false">
        <x-slot name="heading">{{ ($reactivation['ended'] ?? false) ? 'Abo reaktivieren' : 'Kündigung zurückziehen' }}</x-slot>
        @if ($reactivation)
            <p>Das Testabo für <strong>{{ $reactivation['station'] }}</strong> wird fortgesetzt.</p>
            <p>Der bisherige Stationspreis und Monatsrhythmus bleiben erhalten. Es beginnt keine neue Testphase. Das Abo verlängert sich wieder bis zur nächsten Kündigung.</p>
            <p>Im lokalen Testbetrieb entstehen weiterhin keine Rechnungen oder Bankeinzüge.</p>
        @endif
        @error('reactivation')<p role="alert" class="sd-field-error">{{ $message }}</p>@enderror
        <x-slot name="footer">
            <div class="sd-settings-actions">
                <x-filament::button wire:click="confirmReactivation" wire:loading.attr="disabled">Fortsetzung jetzt bestätigen</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'reactivate-subscription' })">Zurück</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</div>
