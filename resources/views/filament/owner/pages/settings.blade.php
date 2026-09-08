<x-filament-panels::page>
    <div class="sd-settings">
        <div role="tablist" aria-label="Kunden-Einstellungen" class="sd-settings-tabs">
            <button type="button" id="settings-tab-subscriptions" role="tab" aria-selected="true" aria-controls="settings-panel-subscriptions">Abos &amp; Laufzeiten</button>
        </div>
        <section id="settings-panel-subscriptions" role="tabpanel" aria-labelledby="settings-tab-subscriptions">
            @livewire(\App\Filament\Owner\Pages\Subscriptions::class, [], key('settings-subscriptions'))
        </section>
    </div>
</x-filament-panels::page>
