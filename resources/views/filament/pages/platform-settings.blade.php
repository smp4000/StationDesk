<x-filament-panels::page>
    @php
        $groups = [
            'billing' => ['title' => 'Abrechnung', 'description' => 'Monatlicher Preis je Station für neue Verträge. Bestehende Vereinbarungen bleiben unverändert.', 'fields' => [
                'gross_amount' => ['Bruttopreis pro Monat (EUR)', 'text', 'Zum Beispiel 1,00. Enthält 19 % Umsatzsteuer.'],
                'prenotification_days' => ['SEPA-Vorabankündigung (Kalendertage)', 'number', '1 bis 365 Tage. Die Frist muss mit der jeweiligen Vertragsvereinbarung übereinstimmen.'],
            ]],
            'creditor' => ['title' => 'Gläubiger', 'description' => 'Jede Speicherung erzeugt eine neue Fassung der Gläubigerdaten.', 'fields' => [
                'company_name' => ['Firmierung', 'text'], 'street' => ['Straße und Hausnummer', 'text'],
                'postal_code' => ['Postleitzahl', 'text'], 'city' => ['Ort', 'text'],
                'country_code' => ['Ländercode', 'text', 'Zwei Buchstaben, zum Beispiel DE.'],
                'creditor_identifier' => ['Gläubiger-ID', 'text'], 'account_holder' => ['Kontoinhaber', 'text'],
                'iban' => ['IBAN', 'password', 'Bei Änderung vollständig eingeben. Leer lassen, um die gespeicherte IBAN zu behalten.'],
                'bic' => ['BIC (optional)', 'text'],
            ]],
            'smtp' => ['title' => 'E-Mail', 'description' => 'Plattform-Absender für künftige Bestätigungen und Vorabankündigungen. Die Verbindung lässt sich mit einer Testmail prüfen.', 'fields' => [
                'host' => ['SMTP-Server', 'text'], 'port' => ['Port', 'number'],
                'encryption' => ['Verschlüsselung', 'select'], 'username' => ['Benutzername', 'text'],
                'password' => ['SMTP-Passwort', 'password', 'Bei Änderung neu eingeben. Leer lassen, um das gespeicherte Passwort zu behalten.'],
                'from_address' => ['Absender-E-Mail', 'email'], 'from_name' => ['Absendername', 'text'],
            ]],
            'fints' => ['title' => 'FinTS', 'description' => 'Verbindungsparameter und reine Kontenabfrage mit Handy-Freigabe testen. Lastschrifteinreichung ist noch nicht angebunden.', 'fields' => [
                'bank_name' => ['Bankname', 'text'], 'bank_code' => ['Bankleitzahl', 'text', 'Acht Ziffern.'],
                'endpoint' => ['FinTS-Endpunkt', 'url', 'HTTPS-Adresse aus den Bankunterlagen.'],
                'product_id' => ['Eigene Produktregistrierungsnummer', 'text'],
            ]],
        ];
    @endphp
    <section class="sd-intro"><div><span class="sd-eyebrow">PLATTFORM</span><h2>Die Grundlagen an einem Ort.</h2><p>Jeder Bereich wird separat gespeichert. Beim Speichern werden keine E-Mails oder Bankaufträge versendet.</p></div></section>
    <div x-data="{ tab: 'billing' }" class="sd-settings">
        <div role="tablist" aria-label="Einstellungsbereiche" class="sd-settings-tabs">
            @foreach ($groups as $group => $definition)
                <button type="button" id="tab-{{ $group }}" role="tab" aria-controls="panel-{{ $group }}"
                    x-bind:aria-selected="tab === '{{ $group }}'" x-on:click="tab = '{{ $group }}'"
                    x-on:keydown.right.prevent="const next = $el.nextElementSibling || $el.parentElement.firstElementChild; next.focus(); next.click()"
                    x-on:keydown.left.prevent="const previous = $el.previousElementSibling || $el.parentElement.lastElementChild; previous.focus(); previous.click()"
                    x-bind:tabindex="tab === '{{ $group }}' ? 0 : -1">
                    {{ $definition['title'] }}
                    @if ($errors->has('data.'.$group.'.*')) <span aria-label="Fehler">!</span> @endif
                </button>
            @endforeach
        </div>
        @foreach ($groups as $group => $definition)
            <section x-show="tab === '{{ $group }}'" @if ($group !== 'billing') x-cloak @endif id="panel-{{ $group }}" role="tabpanel" aria-labelledby="tab-{{ $group }}" class="sd-surface">
                <header><h2>{{ $definition['title'] }}</h2><span>{{ $revisions[$group] ? 'Gespeichert · Stand '.$revisions[$group] : 'Noch nicht gespeichert' }}</span></header>
                <form wire:submit="save('{{ $group }}')" class="sd-settings-form">
                    <p>{{ $definition['description'] }}</p>
                    @if ($group === 'billing')
                        <p class="sd-settings-note">Vereinbarte Eckdaten: 30 Tage Trial; bei Verzug zunächst warnen, nach 30 Tagen Änderungen sperren; sechs Monate Exportzugriff nach Vertragsende. Die Abläufe sind noch in Umsetzung.</p>
                    @endif
                    @if ($secretsSaved[$group]) <p class="sd-settings-note">{{ $group === 'smtp' ? 'Ein Passwort ist gespeichert.' : 'Eine IBAN ist gespeichert.' }} Der gespeicherte Wert wird nicht angezeigt.</p> @endif
                    @error('data.'.$group.'.revision') <p role="alert" class="sd-field-error">{{ $message }}</p> @enderror
                    <div class="sd-settings-grid">
                        @foreach ($definition['fields'] as $field => $details)
                            @php($fieldId = $group.'-'.$field)
                            <div class="sd-settings-field">
                                <label for="{{ $fieldId }}">{{ $details[0] }}</label>
                                @if ($details[1] === 'select')
                                    <select id="{{ $fieldId }}" wire:model="data.{{ $group }}.{{ $field }}" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error">
                                        <option value="starttls">STARTTLS</option><option value="smtps">TLS (SMTPS)</option>
                                    </select>
                                @else
                                    <input id="{{ $fieldId }}" type="{{ $details[1] }}" wire:model="data.{{ $group }}.{{ $field }}"
                                        autocomplete="{{ $details[1] === 'password' ? 'new-password' : 'off' }}"
                                        aria-invalid="{{ $errors->has('data.'.$group.'.'.$field) ? 'true' : 'false' }}"
                                        aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error">
                                @endif
                                <small id="{{ $fieldId }}-help">{{ $details[2] ?? '' }}</small>
                                <span id="{{ $fieldId }}-error" class="sd-field-error" role="alert">@error('data.'.$group.'.'.$field) {{ $message }} @enderror</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="sd-settings-actions">
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ $definition['title'] }} speichern</x-filament::button>
                        <x-filament::button type="button" color="gray" wire:click="reloadGroup('{{ $group }}')" wire:confirm="Ungespeicherte Eingaben in diesem Bereich verwerfen und neu laden?" wire:loading.attr="disabled">Neu laden</x-filament::button>
                    </div>
                </form>
                @if ($group === 'smtp')
                    <form wire:submit="sendTestMail" class="sd-settings-form" aria-labelledby="smtp-test-title">
                        <h3 id="smtp-test-title">Testmail senden</h3>
                        <p>Verwendet die zuletzt gespeicherten SMTP-Einstellungen. Änderungen bitte zuerst speichern. Ein Klick sendet eine echte Testmail an die angegebene Adresse.</p>
                        <div class="sd-settings-field">
                            <label for="smtp-test-recipient">Empfängeradresse</label>
                            <input id="smtp-test-recipient" type="email" wire:model="testRecipient" autocomplete="email" required
                                aria-invalid="{{ $errors->has('testRecipient') ? 'true' : 'false' }}" aria-describedby="smtp-test-error">
                            <span id="smtp-test-error" class="sd-field-error" role="alert">@error('testRecipient') {{ $message }} @enderror</span>
                        </div>
                        <div class="sd-settings-actions">
                            <x-filament::button type="submit" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="sendTestMail">Testmail senden</span>
                                <span wire:loading wire:target="sendTestMail">Wird gesendet …</span>
                            </x-filament::button>
                        </div>
                    </form>
                @endif
                @if ($group === 'fints')
                    <div class="sd-settings-form">
                        <h3>Verbindung prüfen</h3>
                        <p>Prüft die gespeicherte Bankadresse und das TLS-Zertifikat. Es werden keine Bankzugangsdaten übertragen. Ein HTTP-Status bestätigt noch keinen FinTS-Login.</p>
                        <div class="sd-settings-actions"><x-filament::button type="button" wire:click="testFintsConnection" wire:loading.attr="disabled">Verbindung testen</x-filament::button></div>
                        @error('fintsTest') <p role="alert" class="sd-field-error">{{ $message }}</p> @enderror
                        @if ($fintsConnectionResult) <p role="status">{{ $fintsConnectionResult }}</p> @endif
                    </div>
                    <div class="sd-settings-form" aria-labelledby="bank-test-title">
                        <h3 id="bank-test-title">Kontenabfrage mit Handy-Freigabe</h3>
                        <p>Fragt ausschließlich die Kontenliste ab. Ob eine Bestätigung in SecureGo plus erforderlich ist, entscheidet die Bank. Es werden keine Zahlungen ausgelöst.</p>
                        @error('bankTest') <p role="alert" class="sd-field-error">{{ $message }}</p> @enderror
                        @if (empty($bankState))
                            <form wire:submit="startBankTest" class="sd-settings-grid">
                                <div class="sd-settings-field"><label for="bank-login">VR-NetKey / Alias</label><input id="bank-login" wire:model="bankLogin" autocomplete="off" required maxlength="100">@error('bankLogin') <span role="alert" class="sd-field-error">{{ $message }}</span> @enderror</div>
                                <div class="sd-settings-field"><label for="bank-pin">Onlinebanking-PIN</label><input id="bank-pin" type="password" wire:model="bankPin" autocomplete="new-password" required maxlength="100">@error('bankPin') <span role="alert" class="sd-field-error">{{ $message }}</span> @enderror</div>
                                <p class="sd-settings-note">Die Zugangsdaten werden nur für diesen Test verschlüsselt zwischengespeichert. Er ist höchstens zehn Minuten fortsetzbar. Bitte zuvor die Bankparameter speichern.</p>
                                <div><x-filament::button type="submit" wire:loading.attr="disabled">Banktest starten</x-filament::button></div>
                            </form>
                            <x-filament::button type="button" color="gray" wire:click="cancelBankTest" wire:loading.attr="disabled">Alten Test nach Seitenneuladen beenden</x-filament::button>
                        @elseif (in_array($bankState['phase'], ['mode', 'medium']))
                            <form wire:submit="continueBankTest" class="sd-settings-grid">
                                <div class="sd-settings-field"><label for="bank-choice">{{ $bankState['phase'] === 'mode' ? 'Handy-Verfahren auswählen' : 'Freigabegerät auswählen' }}</label>
                                    <select id="bank-choice" wire:model="bankChoice" required><option value="">Bitte auswählen</option>@foreach ($bankState['options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                </div>
                                <div><x-filament::button type="submit" wire:loading.attr="disabled">Auswahl bestätigen und abfragen</x-filament::button></div>
                            </form>
                        @elseif ($bankState['phase'] === 'waiting')
                            <p role="status">Bitte die Abfrage auf deinem Handy bestätigen.</p>
                            <p>{{ $bankState['challenge'] }}</p>
                            <p>Nach der Bestätigung hier prüfen. Die von der Bank vorgegebenen Wartezeiten werden eingehalten.</p>
                            <div><x-filament::button type="button" wire:click="continueBankTest" wire:loading.attr="disabled">Freigabestatus prüfen</x-filament::button></div>
                        @elseif ($bankState['phase'] === 'done')
                            <p role="status">Kontenabfrage erfolgreich. {{ count($bankState['accounts']) }} Konten gefunden.</p>
                            <ul>@foreach ($bankState['accounts'] as $account)<li>{{ $account }}</li>@endforeach</ul>
                        @endif
                        @if (! empty($bankState))<div><x-filament::button type="button" color="gray" wire:click="cancelBankTest" wire:loading.attr="disabled">Test beenden</x-filament::button></div>@endif
                        <p wire:loading wire:target="startBankTest,continueBankTest" role="status">Bankantwort wird abgewartet …</p>
                    </div>
                @endif
                @if (! empty($history[$group]))
                    <div class="sd-settings-history"><h3>Letzte Fassungen</h3><ul>
                        @foreach ($history[$group] as $version)
                            <li>Fassung {{ $version->id }} · {{ $version->created_at }} UTC · Admin #{{ $version->created_by }}</li>
                        @endforeach
                    </ul></div>
                @endif
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
