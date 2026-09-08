<?php

namespace App\Settings;

use App\Models\Owner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persönliche, fest definierte Farbauswahl; niemals freies CSS oder fremde Benutzerreferenzen übernehmen. */
class OwnerAppearance
{
    /** Website-Farben aus den offiziellen Stylesheets; Quellen und Zuordnung stehen im P01-Blueprint. */
    public static function schemes(): array
    {
        return [
            'petrol' => ['name' => 'StationDeck · Petrol', 'sidebar' => '#133e3d', 'nav' => '#dbe9e0', 'active' => '#225751', 'primary' => '#147568', 'accent' => '#147568', 'canvas' => '#f4f5ef', 'soft' => '#e4ece0', 'ink' => '#143c3d'],
            'aral' => ['name' => 'Aral · Blau', 'sidebar' => '#0064cc', 'nav' => '#ffffff', 'active' => '#032b5a', 'primary' => '#0064cc', 'accent' => '#0064cc', 'canvas' => '#f7f7f7', 'soft' => '#e9e9e9', 'ink' => '#252530', 'muted' => '#666666', 'line' => '#eaeaea', 'button_text' => '#ffffff', 'hover' => '#032b5a', 'hover_text' => '#ffffff'],
            'esso' => ['name' => 'Esso · Rot', 'sidebar' => '#dc241f', 'nav' => '#ffffff', 'active' => '#0e469b', 'primary' => '#dc241f', 'accent' => '#0e469b', 'canvas' => '#fafafa', 'soft' => '#f0f0f0', 'ink' => '#2b2626', 'muted' => '#606060', 'line' => '#d4d4d4', 'button_text' => '#ffffff', 'hover' => '#0e469b', 'hover_text' => '#ffffff'],
            'shell' => ['name' => 'Shell · Gelb', 'sidebar' => '#ffc800', 'nav' => '#4a4a4a', 'active' => '#4a4a4a', 'active_nav' => '#ffffff', 'primary' => '#ffc800', 'accent' => '#ffc800', 'canvas' => '#ffffff', 'soft' => '#f5f5f5', 'ink' => '#4a4a4a', 'muted' => '#4a4a4a', 'line' => '#d4d4d4', 'button_text' => '#4a4a4a', 'hover' => '#4a4a4a', 'hover_text' => '#ffffff'],
            'bft' => ['name' => 'bft · Weiß / Grau', 'sidebar' => '#ffffff', 'nav' => '#35354b', 'active' => '#35354b', 'active_nav' => '#ffffff', 'primary' => '#35354b', 'accent' => '#484866', 'canvas' => '#ffffff', 'soft' => '#ffffff', 'ink' => '#35354b', 'muted' => '#484866', 'line' => '#c7c7cd', 'button_text' => '#ffffff', 'hover' => '#484866', 'hover_text' => '#ffffff'],
            'oil' => ['name' => 'OIL! · Grün / Lila', 'sidebar' => '#00914c', 'nav' => '#000000', 'active' => '#681f79', 'active_nav' => '#ffffff', 'primary' => '#681f79', 'accent' => '#00914c', 'canvas' => '#ffffff', 'soft' => '#ededed', 'ink' => '#681f79', 'muted' => '#505050', 'line' => '#bfbfbf', 'button_text' => '#ffffff', 'hover' => '#00914c', 'hover_text' => '#000000'],
        ];
    }

    /** Auch auf zentralen Auth-Seiten ohne angemeldeten Owner bleibt eine sichere Standardpalette verfügbar. */
    public function currentKey(): string
    {
        $owner = auth('web')->user();
        $key = $owner instanceof Owner ? Owner::query()->whereKey($owner->id)->value('color_scheme') : null;

        return array_key_exists($key ?? '', self::schemes()) ? $key : 'petrol';
    }

    public function current(): array
    {
        return self::schemes()[$this->currentKey()];
    }

    /** Speichert nur die Auswahl der angemeldeten Person; andere Benutzer und das Admin-Panel bleiben unverändert. */
    public function save(string $key): void
    {
        $owner = auth('web')->user();
        abort_unless($owner instanceof Owner, 403);
        if (! array_key_exists($key, self::schemes())) {
            throw ValidationException::withMessages(['colorScheme' => 'Bitte ein angebotenes Farbschema auswählen.']);
        }
        DB::connection('central')->transaction(function () use ($owner, $key): void {
            $current = Owner::query()->lockForUpdate()->findOrFail($owner->id);
            if ($current->color_scheme === $key) {
                return;
            }
            $current->forceFill(['color_scheme' => $key])->save();
            DB::connection('central')->table('audit_events')->insert([
                'tenant_id' => $current->tenant_id, 'actor_type' => 'owner', 'actor_id' => (string) $current->id,
                'action' => 'owner.appearance_changed', 'subject_id' => $key, 'occurred_at' => now(),
            ]);
        });
    }
}
