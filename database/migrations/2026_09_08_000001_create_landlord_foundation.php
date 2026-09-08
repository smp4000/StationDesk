<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Zentrale Registry und strikt getrennte Identitäten; fachlicher Lifecycle gehört zur Station. */
return new class extends Migration
{
    protected $connection = 'central';

    /** Erstellt normalisierte P01-Stammdaten; Kommentare erklären die fachlichen Felder. */
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Unveränderliche interne Mandantenreferenz.');
            $table->string('company_name')->comment('Firmenname des vertraglichen Betreibers.');
            $table->string('provisioning_status', 32)->default('pending')->comment('Technischer Bereitstellungszustand, kein Abo-Status.');
            $table->string('tenancy_db_name', 64)->unique()->comment('Intern erzeugter MySQL-Schemaname.');
            $table->string('tenancy_db_username', 32)->comment('Ausschließlich diesem Schema zugeordneter Datenbanknutzer.');
            $table->text('tenancy_db_password')->comment('Verschlüsseltes Datenbankpasswort.');
            $table->json('data')->nullable()->comment('Paketkompatibilität; Fach- und Verbindungsdaten stehen ausschließlich in eigenen Spalten.');
            $table->timestamps();
        });
        Schema::connection('central')->create('owners', function (Blueprint $table): void {
            $table->id()->comment('Zentrale Owner-Identität.');
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->restrictOnDelete()->comment('Genau ein Vertrags-Owner je Mandant.');
            $table->string('name')->comment('Anzeigename für Anmeldung und Benachrichtigungen.');
            $table->string('first_name')->comment('Vorname des Chefs.');
            $table->string('last_name')->comment('Nachname des Chefs.');
            $table->string('email')->unique()->comment('Zentrale eindeutige Anmeldeadresse.');
            $table->timestamp('email_verified_at')->nullable()->comment('Zeitpunkt der bestätigten E-Mail-Adresse.');
            $table->string('password')->comment('Gehashtes Anmeldepasswort.');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('central')->create('super_admins', function (Blueprint $table): void {
            $table->id()->comment('Separate Plattformidentität.');
            $table->string('name')->comment('Anzeigename im Plattformpanel.');
            $table->string('email')->unique()->comment('Anmeldeadresse für das Plattformpanel.');
            $table->string('password')->comment('Gehashtes Plattformpasswort.');
            $table->text('app_authentication_secret')->nullable()->comment('Verschlüsseltes TOTP-Geheimnis; Panelzugriff erfordert Einrichtung.');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('central')->create('admin_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary()->comment('Plattformadresse, unabhängig von Owner-Reset-Tokens.');
            $table->string('token')->comment('Gehashter einmaliger Reset-Nachweis.');
            $table->timestamp('created_at')->nullable()->comment('Ausstellungszeitpunkt für die Ablaufprüfung.');
        });
        Schema::connection('central')->create('audit_events', function (Blueprint $table): void {
            $table->id()->comment('Fortlaufende Ereignisreferenz.');
            $table->uuid('tenant_id')->nullable()->index()->comment('Betroffener Mandant, sofern die Aktion einen Mandanten betrifft.');
            $table->string('actor_type', 32)->comment('Art des Akteurs: System, Owner oder Super-Admin.');
            $table->string('actor_id')->nullable()->comment('Interne Akteursreferenz ohne kopierte Personendaten.');
            $table->string('action', 100)->comment('Entwicklergepflegter Aktionsschlüssel.');
            $table->string('subject_id')->nullable()->comment('Referenz auf das betroffene Fachobjekt.');
            $table->timestamp('occurred_at')->useCurrent()->comment('Zeitpunkt des Ereignisses in UTC.');
        });
    }

    /** Entfernt nur die Tabellen dieser Migration in umgekehrter Abhängigkeitsreihenfolge. */
    public function down(): void
    {
        foreach (['audit_events', 'admin_password_reset_tokens', 'super_admins', 'owners', 'tenants'] as $table) {
            Schema::connection('central')->dropIfExists($table);
        }
    }
};
