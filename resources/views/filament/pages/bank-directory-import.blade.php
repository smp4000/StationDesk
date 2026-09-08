<x-filament-panels::page>
    <section class="sd-intro"><div><span class="sd-eyebrow">BUNDESBANK</span><h2>Den Bankenstamm aktuell halten.</h2><p>Neue öffentliche Bundesbank-CSV hochladen. Die Gültigkeit bestimmt, ab wann ein Datenstand verwendet wird.</p></div></section>
    <section class="sd-surface">
        <header><h2>Verwendeter Datenstand</h2><span>{{ $active ? 'Import #'.$active->id : 'Kein gültiger Import' }}</span></header>
        <div class="sd-settings-form">
            @if ($active)<p>{{ number_format($active->row_count, 0, ',', '.') }} Datensätze · gültig {{ \Carbon\Carbon::parse($active->valid_from)->format('d.m.Y') }} bis {{ \Carbon\Carbon::parse($active->valid_until)->format('d.m.Y') }}</p>
            @else<p role="status">Bitte eine aktuell gültige CSV importieren. Ohne gültigen Bestand kann die IBAN-Hilfe keine Bank zuordnen.</p>@endif
        </div>
    </section>
    <section class="sd-surface">
        <header><h2>Neue CSV einlesen</h2><a href="https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen/download-bankleitzahlen-602592" target="_blank" rel="noopener noreferrer">CSV bei der Bundesbank herunterladen ↗</a></header>
        <form wire:submit="importCsv" class="sd-settings-form">
            <p>Ungepackte öffentliche CSV mit 13 Spalten auswählen (höchstens 10 MB). Den Gültigkeitszeitraum aus der Bundesbank-Veröffentlichung übernehmen.</p>
            <div class="sd-settings-field"><label for="bank-csv">Bundesbank-CSV</label><input id="bank-csv" type="file" accept=".csv" wire:model="csvFile" required aria-describedby="bank-csv-error"><span id="bank-csv-error" role="alert" class="sd-field-error">@error('csvFile'){{ $message }}@enderror</span></div>
            <div class="sd-settings-grid">
                <div class="sd-settings-field"><label for="bank-valid-from">Gültig ab</label><input id="bank-valid-from" type="date" wire:model="validFrom" required>@error('validFrom')<span role="alert" class="sd-field-error">{{ $message }}</span>@enderror</div>
                <div class="sd-settings-field"><label for="bank-valid-until">Gültig bis einschließlich</label><input id="bank-valid-until" type="date" wire:model="validUntil" required>@error('validUntil')<span role="alert" class="sd-field-error">{{ $message }}</span>@enderror</div>
            </div>
            <p class="sd-settings-note">Künftige Datenstände werden erst ab ihrem Gültigkeitsbeginn verwendet. Bei mehreren gültigen Fassungen gilt der jüngste Gültigkeitsbeginn, danach der jüngste Import. Fehlerhafte Dateien verändern den bisherigen Bestand nicht.</p>
            <div class="sd-settings-actions"><x-filament::button type="submit" wire:loading.attr="disabled">CSV prüfen und importieren</x-filament::button><span wire:loading role="status">Datei wird hochgeladen oder geprüft …</span></div>
        </form>
    </section>
    <section class="sd-surface">
        <header><h2>Letzte Importe</h2><span>Maximal 20 Datenstände</span></header>
        <div class="sd-table-wrap"><table><thead><tr><th>Import</th><th>Gültigkeit</th><th>Datensätze</th><th>Status</th></tr></thead><tbody>
            @forelse ($imports as $import)
                <tr><td>#{{ $import->id }}</td><td>{{ \Carbon\Carbon::parse($import->valid_from)->format('d.m.Y') }} – {{ \Carbon\Carbon::parse($import->valid_until)->format('d.m.Y') }}</td><td>{{ number_format($import->row_count, 0, ',', '.') }}</td><td>{{ $active?->id === $import->id ? 'Wird verwendet' : ($import->valid_from > $today ? 'Künftig' : ($import->valid_until < $today ? 'Abgelaufen' : 'Andere Fassung wird verwendet')) }}</td></tr>
            @empty<tr><td colspan="4">Noch keine CSV importiert.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-filament-panels::page>
