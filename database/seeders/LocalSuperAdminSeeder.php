<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/** Explizite lokale Kontoanlage; keine Standardzugänge oder Passwortänderungen durch normales Seeding. */
class LocalSuperAdminSeeder extends Seeder
{
    /** Verlangt lokale Konfiguration, erhält bestehende Konten und protokolliert die Neuanlage atomar. */
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Dieser Super-Admin-Seeder ist ausschließlich lokal erlaubt.');
        }

        $data = Validator::make([
            'name' => config('local-admin.name'),
            'email' => mb_strtolower(trim((string) config('local-admin.email'))),
            'password' => config('local-admin.password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12)],
        ])->validate();

        DB::connection('central')->transaction(function () use ($data): void {
            if (SuperAdmin::query()->where('email', $data['email'])->exists()) {
                $this->command?->info('Super-Admin existiert bereits; Passwort und TOTP bleiben unverändert.');

                return;
            }

            $admin = (new SuperAdmin)->forceFill($data);
            $admin->save();
            DB::connection('central')->table('audit_events')->insert([
                'actor_type' => 'system', 'action' => 'super_admin.created',
                'subject_id' => (string) $admin->id, 'occurred_at' => now(),
            ]);
            $this->command?->info('Lokaler Super-Admin angelegt. Beim ersten Login TOTP einrichten.');
        });
    }
}
