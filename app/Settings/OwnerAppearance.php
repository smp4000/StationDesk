<?php

namespace App\Settings;

use App\Models\Owner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persönliche, fest definierte Farbauswahl; niemals freies CSS oder fremde Benutzerreferenzen übernehmen. */
class OwnerAppearance
{
    /** An Markenfarben angelehnte Paletten mit angepassten Kontrasten für die Anwendungsoberfläche. */
    public static function schemes(): array
    {
        return [
            'petrol' => ['name' => 'StationDeck · Petrol', 'sidebar' => '#133e3d', 'nav' => '#dbe9e0', 'active' => '#225751', 'primary' => '#147568', 'accent' => '#147568', 'canvas' => '#f4f5ef', 'soft' => '#e4ece0', 'ink' => '#143c3d'],
            'aral' => ['name' => 'Aral · Blau', 'sidebar' => '#0064cc', 'nav' => '#ffffff', 'active' => '#00458d', 'primary' => '#0064cc', 'accent' => '#0064cc', 'canvas' => '#f3f6fb', 'soft' => '#e5effc', 'ink' => '#14345b'],
            'esso' => ['name' => 'Esso · Rot', 'sidebar' => '#c8102e', 'nav' => '#ffffff', 'active' => '#920b22', 'primary' => '#c8102e', 'accent' => '#c8102e', 'canvas' => '#faf5f5', 'soft' => '#fbe7eb', 'ink' => '#491b25'],
            'shell' => ['name' => 'Shell · Gelb', 'sidebar' => '#ffd500', 'nav' => '#292929', 'active' => '#e8bb00', 'primary' => '#806000', 'accent' => '#ffd500', 'canvas' => '#faf9f3', 'soft' => '#fff4c2', 'ink' => '#443600'],
            'bft' => ['name' => 'bft · Weiß / Grau', 'sidebar' => '#f1f2f3', 'nav' => '#30343b', 'active' => '#d7dce1', 'primary' => '#4b5563', 'accent' => '#9ca3af', 'canvas' => '#f7f8fa', 'soft' => '#eceff2', 'ink' => '#30343b'],
            'oil' => ['name' => 'OIL! · Grün / Lila', 'sidebar' => '#681f79', 'nav' => '#ffffff', 'active' => '#4d1759', 'primary' => '#007840', 'accent' => '#00914c', 'canvas' => '#f5f7f4', 'soft' => '#e6f2e9', 'ink' => '#542162'],
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
