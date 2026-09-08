<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Legt Plattformkonten ausschließlich interaktiv an; keine Standardpasswörter oder öffentliche Registrierung. */
#[Signature('stationdeck:create-super-admin')]
#[Description('Legt einen Super-Admin an, der beim ersten Login TOTP einrichten muss.')]
class CreateSuperAdmin extends Command
{
    /** Fragt das Passwort verdeckt ab, validiert die Eingaben und protokolliert die Kontoanlage. */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Die Kontoanlage benötigt eine interaktive Eingabe.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->ask('Name'),
            'email' => mb_strtolower(trim((string) $this->ask('E-Mail-Adresse'))),
            'password' => $this->secret('Passwort (mindestens 12 Zeichen)'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:central.super_admins,email'],
            'password' => ['required', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        DB::connection('central')->transaction(function () use ($data): void {
            $admin = (new SuperAdmin)->forceFill($data);
            $admin->save();
            DB::connection('central')->table('audit_events')->insert([
                'actor_type' => 'system', 'action' => 'super_admin.created', 'subject_id' => (string) $admin->id,
                'occurred_at' => now(),
            ]);
        });
        $this->info('Super-Admin angelegt. Beim ersten Login muss TOTP eingerichtet werden.');

        return self::SUCCESS;
    }
}
