<?php

namespace App\Onboarding;

use App\Models\Owner;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Lokale Registrierung als atomarer Auftrag; erstellt weder ein Schema noch einen kostenpflichtigen Vertrag. */
class RegisterOwner
{
    /** Ohne freigegebene Vertragstexte ist die Registrierung ausschließlich lokal und in Tests verfügbar. */
    public static function available(): bool
    {
        return app()->environment('local', 'testing');
    }

    /** Validiert auf der Vertrauensgrenze und nimmt ausschließlich bekannte Formularfelder an. */
    public function create(#[SensitiveParameter] array $input): Owner
    {
        abort_unless(self::available(), 403);
        $input['email'] = Str::lower(trim($input['email'] ?? ''));
        $rules = [
            'email' => ['required', 'email', 'max:255', 'unique:central.owners,email'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed:passwordConfirmation',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Bcrypt begrenzt Bytes, nicht Unicode-Zeichen; eine spätere Kürzung wäre mehrdeutig.
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('Das Passwort ist zu lang. Bitte bei Sonderzeichen ein kürzeres Passwort wählen.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:50'], 'vat_id' => ['nullable', 'string', 'max:32'],
            'test_registration' => ['accepted'],
        ];
        foreach (['first_name', 'last_name', 'company_name', 'billing_street', 'billing_city', 'station_name', 'station_street', 'station_city'] as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
            $input[$field] = trim($input[$field] ?? '');
        }
        foreach (['billing_postal_code', 'station_postal_code'] as $field) {
            $rules[$field] = ['required', 'string', 'max:20'];
        }
        foreach (['billing_country_code', 'station_country_code'] as $field) {
            $rules[$field] = ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'];
        }
        $validator = Validator::make($input, $rules, [
            'required' => 'Bitte dieses Feld ausfüllen.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
            'unique' => 'Diese E-Mail-Adresse ist bereits registriert. Bitte anmelden oder das Passwort zurücksetzen.',
            'min' => 'Das Passwort muss mindestens 12 Zeichen enthalten.', 'max' => 'Die Eingabe ist zu lang.',
            'confirmed' => 'Die Passwörter stimmen nicht überein.', 'accepted' => 'Bitte die lokale Testregistrierung bestätigen.',
            'size' => 'Bitte den zweistelligen Ländercode eingeben.', 'regex' => 'Bitte zwei Großbuchstaben als Ländercode eingeben.',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
        $data = $validator->validated();
        try {
            return DB::connection('central')->transaction(function () use ($data): Owner {
                $price = DB::connection('central')->table('billing_settings_versions')->latest('id')->first();
                $tenant = new Tenant;
                $tenant->forceFill([
                    'id' => (string) Str::uuid(), 'company_name' => $data['company_name'], 'provisioning_status' => 'pending',
                    'tenancy_db_name' => 'sd_t_'.bin2hex(random_bytes(16)),
                    'tenancy_db_username' => 'sdu_'.bin2hex(random_bytes(14)),
                    'tenancy_db_password' => bin2hex(random_bytes(32)),
                ])->save();
                $owner = new Owner;
                $owner->forceFill([
                    'tenant_id' => $tenant->id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'name' => $data['first_name'].' '.$data['last_name'], 'email' => $data['email'], 'password' => $data['password'],
                ])->save();
                $db = DB::connection('central');
                $db->table('tenant_billing_profiles')->insert([
                    'tenant_id' => $tenant->id, 'street' => $data['billing_street'], 'postal_code' => $data['billing_postal_code'],
                    'city' => $data['billing_city'], 'country_code' => $data['billing_country_code'],
                    'phone' => $data['phone'] ?? null, 'vat_id' => $data['vat_id'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('registration_requests')->insert([
                    'tenant_id' => $tenant->id, 'station_id' => (string) Str::uuid(), 'employee_id' => (string) Str::uuid(),
                    'station_name' => $data['station_name'], 'station_street' => $data['station_street'],
                    'station_postal_code' => $data['station_postal_code'], 'station_city' => $data['station_city'],
                    'station_country_code' => $data['station_country_code'], 'gross_cents' => $price?->gross_cents ?? 100,
                    'tax_basis_points' => $price?->tax_basis_points ?? 1900, 'billing_settings_version_id' => $price?->id,
                    'is_test_registration' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('audit_events')->insert([
                    'tenant_id' => $tenant->id, 'actor_type' => 'owner', 'actor_id' => (string) $owner->id,
                    'action' => 'registration.created', 'subject_id' => $tenant->id, 'occurred_at' => now(),
                ]);

                return $owner;
            });
        } catch (UniqueConstraintViolationException) {
            // Auch ein konkurrierender zweiter Klick darf weder Duplikate noch SQL mit Geheimnissen ausgeben.
            throw ValidationException::withMessages(['data.email' => 'Diese E-Mail-Adresse ist bereits registriert. Bitte anmelden.']);
        }
    }
}
