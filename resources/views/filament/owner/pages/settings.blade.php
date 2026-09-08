<x-filament-panels::page>
    <div class="sd-settings" x-data="{ tab: new URLSearchParams(location.search).get('tab') === 'appearance' ? 'appearance' : 'subscriptions' }">
        <div role="tablist" aria-label="Kunden-Einstellungen" class="sd-settings-tabs">
            <button type="button" id="settings-tab-appearance" role="tab" x-bind:aria-selected="tab === 'appearance'" x-bind:tabindex="tab === 'appearance' ? 0 : -1" x-on:click="tab = 'appearance'" x-on:keydown.right.prevent="$el.nextElementSibling.focus(); $el.nextElementSibling.click()" x-on:keydown.left.prevent="$el.nextElementSibling.focus(); $el.nextElementSibling.click()" aria-controls="settings-panel-appearance">Darstellung</button>
            <button type="button" id="settings-tab-subscriptions" role="tab" x-bind:aria-selected="tab === 'subscriptions'" x-bind:tabindex="tab === 'subscriptions' ? 0 : -1" x-on:click="tab = 'subscriptions'" x-on:keydown.right.prevent="$el.previousElementSibling.focus(); $el.previousElementSibling.click()" x-on:keydown.left.prevent="$el.previousElementSibling.focus(); $el.previousElementSibling.click()" aria-controls="settings-panel-subscriptions">Abos &amp; Laufzeiten</button>
        </div>
        <section x-show="tab === 'appearance'" x-cloak id="settings-panel-appearance" role="tabpanel" aria-labelledby="settings-tab-appearance" class="sd-surface">
            <header><h2>Dein Farbschema</h2><span>Persönliche Einstellung</span></header>
            <form wire:submit="saveAppearance" class="sd-settings-form">
                <p>Wähle die Farben für deinen Arbeitsplatz. Deine Auswahl gilt nur für deinen Benutzer.</p>
                <fieldset><legend class="sr-only">Farbschema auswählen</legend><div class="sd-palette-grid">
                    @foreach (\App\Settings\OwnerAppearance::schemes() as $key => $scheme)
                        <label class="sd-palette-card">
                            <input type="radio" wire:model="colorScheme" value="{{ $key }}" name="color-scheme">
                            <span class="sd-palette-title">{{ $scheme['name'] }}</span>
                            <span class="sd-palette-preview" aria-hidden="true" style="background:{{ $scheme['canvas'] }}">
                                <span style="background:{{ $scheme['sidebar'] }};color:{{ $scheme['nav'] }}">S<span style="background:{{ $scheme['active'] }};height:8px;width:22px;display:block;margin-top:12px"></span></span>
                                <span><span style="display:block;background:{{ $scheme['soft'] }};color:{{ $scheme['ink'] }};padding:8px;border-radius:5px">Dein Betrieb</span><span style="display:inline-block;background:{{ $scheme['primary'] }};color:{{ $scheme['button_text'] ?? '#ffffff' }};padding:5px 12px;margin-top:10px;border-radius:4px">Speichern</span></span>
                            </span>
                        </label>
                    @endforeach
                </div></fieldset>
                @error('colorScheme')<p role="alert" class="sd-field-error">{{ $message }}</p>@enderror
                <div><x-filament::button type="submit" wire:loading.attr="disabled">Farbschema speichern</x-filament::button></div>
            </form>
        </section>
        <section x-show="tab === 'subscriptions'" id="settings-panel-subscriptions" role="tabpanel" aria-labelledby="settings-tab-subscriptions">
            @livewire(\App\Filament\Owner\Pages\Subscriptions::class, [], key('settings-subscriptions'))
        </section>
    </div>
</x-filament-panels::page>
