<?php

namespace App\Settings;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Zentrale Plattformkonfiguration mit Versionsschutz, Geheimnisfilterung und getrenntem Audit. */
class PlatformSettingsStore
{
    private const TABLES = [
        'billing' => 'billing_settings_versions', 'creditor' => 'creditor_profile_versions',
        'smtp' => 'platform_smtp_settings', 'fints' => 'platform_fints_settings',
    ];

    /** Prüft den zentralen Admin-Guard erneut, auch für direkte Livewire-Aktionen. */
    public function authorize(): SuperAdmin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof SuperAdmin && $admin->exists && filled($admin->getAppAuthenticationSecret()), 403);

        return $admin;
    }

    /** Liefert ausschließlich bearbeitbare Felder; bestehende Geheimnisse werden nicht entschlüsselt. */
    public function read(string $group): array
    {
        $this->authorize();
        $row = DB::connection('central')->table($this->table($group))->orderByDesc('id')->first();
        $data = array_fill_keys(array_keys($this->rules($group)), '');
        $data = array_replace($data, match ($group) {
            'billing' => ['gross_amount' => '1,00', 'prenotification_days' => 2],
            'creditor' => ['country_code' => 'DE'],
            'smtp' => ['port' => 587, 'encryption' => 'starttls'],
            'fints' => ['bank_name' => 'VR Bank Fulda'],
        });
        if ($row) {
            foreach ($data as $key => $default) {
                if (! in_array($key, ['password', 'iban', 'gross_amount'], true)) {
                    $data[$key] = $row->{$key} ?? $default;
                }
            }
            if ($group === 'billing') {
                $data['gross_amount'] = intdiv($row->gross_cents, 100).','.str_pad((string) ($row->gross_cents % 100), 2, '0', STR_PAD_LEFT);
            }
        }

        return ['data' => $data, 'revision' => $row ? (int) ($row->revision ?? $row->id) : 0,
            'secret_saved' => $row && in_array($group, ['smtp', 'creditor'], true)];
    }

    /** Validiert und speichert einen Bereich atomar; veraltete Formulare müssen neu geladen werden. */
    public function save(string $group, array $input, int $expectedRevision): void
    {
        $admin = $this->authorize();
        $table = $this->table($group);
        foreach (['iban', 'bic', 'country_code', 'creditor_identifier'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = strtoupper(preg_replace('/\s+/', '', $input[$key]));
            }
        }
        $data = Validator::make($input, $this->rules($group), [
            'required' => 'Bitte dieses Feld ausfüllen.', 'integer' => 'Bitte eine ganze Zahl eingeben.',
            'regex' => 'Bitte das angegebene Format verwenden.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
            'url' => 'Bitte eine gültige HTTPS-Adresse eingeben.', 'between' => 'Der Wert liegt außerhalb des zulässigen Bereichs.',
            'max' => 'Die Eingabe ist zu lang.', 'string' => 'Bitte einen Textwert eingeben.',
            'in' => 'Bitte einen der angebotenen Werte auswählen.',
        ])->validate();
        DB::connection('central')->transaction(function () use ($group, $table, $data, $expectedRevision, $admin): void {
            DB::connection('central')->table('platform_settings_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $current = DB::connection('central')->table($table)->orderByDesc('id')->first();
            $revision = $current ? (int) ($current->revision ?? $current->id) : 0;
            if ($revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'Dieser Bereich wurde zwischenzeitlich geändert. Bitte neu laden und deine Eingaben erneut prüfen.']);
            }
            if ($group === 'billing') {
                $parts = explode('.', str_replace(',', '.', $data['gross_amount']));
                $data['gross_cents'] = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
                unset($data['gross_amount']);
                $data['tax_basis_points'] = 1900;
                if ($data['gross_cents'] < 1) {
                    throw ValidationException::withMessages(['gross_amount' => 'Der Preis muss mindestens 0,01 EUR betragen.']);
                }
            }
            $secretField = match ($group) {
                'smtp' => 'password', 'creditor' => 'iban', default => null
            };
            if ($secretField !== null) {
                if (filled($data[$secretField] ?? null)) {
                    $data[$secretField] = Crypt::encryptString($data[$secretField]);
                } elseif ($current) {
                    $data[$secretField] = $current->{$secretField};
                } else {
                    throw ValidationException::withMessages([$secretField => 'Bei der ersten Einrichtung ist dieses Feld erforderlich.']);
                }
            }
            if (in_array($group, ['billing', 'creditor'], true)) {
                $id = DB::connection('central')->table($table)->insertGetId($data + ['created_by' => $admin->id, 'created_at' => now()]);
            } else {
                $data += ['revision' => $revision + 1, 'updated_by' => $admin->id, 'updated_at' => now()];
                if ($current) {
                    $id = $current->id;
                    DB::connection('central')->table($table)->where('id', $id)->update($data);
                } else {
                    $id = DB::connection('central')->table($table)->insertGetId($data + ['created_at' => now()]);
                }
            }
            DB::connection('central')->table('audit_events')->insert([
                'actor_type' => 'super_admin', 'actor_id' => (string) $admin->id,
                'action' => 'platform_settings.'.$group.'.saved', 'subject_id' => (string) $id, 'occurred_at' => now(),
            ]);
        });
    }

    /** Zeigt nur Metadaten der Historie, keine Kontoverbindungen oder Zugangsdaten. */
    public function history(string $group): array
    {
        $this->authorize();
        $table = $this->table($group);
        if (! in_array($group, ['billing', 'creditor'], true)) {
            return [];
        }

        return DB::connection('central')->table($table)->select(['id', 'created_at', 'created_by'])->orderByDesc('id')->limit(10)->get()->all();
    }

    /** Tabellennamen stammen ausschließlich aus einer festen serverseitigen Liste. */
    private function table(string $group): string
    {
        abort_unless(isset(self::TABLES[$group]), 404);

        return self::TABLES[$group];
    }

    /** Fachliche Pflichtfelder; gespeicherte Geheimnisse dürfen durch leere Eingaben erhalten bleiben. */
    private function rules(string $group): array
    {
        $text = ['required', 'string', 'max:255'];

        return array_map(fn (array $rules): array => ['bail', ...$rules], match ($group) {
            'billing' => [
                'gross_amount' => ['required', 'string', 'regex:/^\d{1,6}([,.]\d{1,2})?$/D'],
                'prenotification_days' => ['required', 'integer', 'between:1,365'],
            ],
            'creditor' => [
                'company_name' => $text, 'street' => $text, 'postal_code' => $text, 'city' => $text,
                'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/D'],
                'creditor_identifier' => ['required', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{4,31}$/D'],
                'account_holder' => $text,
                'iban' => ['nullable', 'string', 'max:34', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $value)) {
                        $fail('Bitte eine IBAN mit gültiger Prüfsumme eingeben.');

                        return;
                    }
                    $digits = preg_replace_callback('/[A-Z]/', fn (array $match): string => (string) (ord($match[0]) - 55), substr($value, 4).substr($value, 0, 4));
                    $remainder = 0;
                    foreach (str_split($digits) as $digit) {
                        $remainder = ($remainder * 10 + (int) $digit) % 97;
                    }
                    if ($remainder !== 1) {
                        $fail('Die Prüfsumme der IBAN ist ungültig.');
                    }
                }],
                'bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D'],
            ],
            'smtp' => [
                'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D'],
                'port' => ['required', 'integer', 'between:1,65535'], 'encryption' => ['required', 'in:starttls,smtps'],
                'username' => $text, 'password' => ['nullable', 'string', 'max:4096'],
                'from_address' => ['required', 'email', 'max:255'], 'from_name' => $text,
            ],
            'fints' => [
                'bank_name' => $text, 'bank_code' => ['required', 'string', 'regex:/^\d{8}$/D'],
                'endpoint' => ['required', 'url:https', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                    $parts = parse_url($value);
                    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                        $fail('Der Endpunkt darf keine Zugangsdaten oder URL-Fragmente enthalten.');
                    }
                }], 'product_id' => $text,
            ],
        });
    }
}
