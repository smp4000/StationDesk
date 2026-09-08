<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Minimale Stations- und Chef-Daten pro Mandant; keine Personalakten in der Landlord-Datenbank. */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** Erstellt nur das Onboarding-Grundgerüst, ohne spätere Personal-Fachregeln vorwegzunehmen. */
    public function up(): void
    {
        Schema::connection('tenant')->create('stations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Stabile Station-ID auch für zentrale Abrechnungsreferenzen.');
            $table->string('name')->comment('Vom Betreiber gewählter Stationsname.');
            $table->string('street')->comment('Straße und Hausnummer der Station.');
            $table->string('postal_code', 20)->comment('Postleitzahl als Text einschließlich führender Nullen.');
            $table->string('city')->comment('Ort der Station.');
            $table->char('country_code', 2)->comment('ISO-Ländercode der Stationsanschrift.');
            $table->string('lifecycle_status', 32)->default('trial')->comment('Stations-Lifecycle gemäß Produktvorgabe.');
            $table->timestamps();
        });
        Schema::connection('tenant')->create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Interne Mitarbeiterreferenz.');
            $table->unsignedBigInteger('owner_id')->nullable()->unique()->comment('Optionale Referenz auf den zentralen Owner; keine DB-übergreifende FK-Annahme.');
            $table->string('first_name')->comment('Vorname des Mitarbeiters.');
            $table->string('last_name')->comment('Nachname des Mitarbeiters.');
            $table->timestamps();
        });
        Schema::connection('tenant')->create('employee_station_assignments', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete()->comment('Zugeordneter Mitarbeiter.');
            $table->foreignUuid('station_id')->constrained('stations')->restrictOnDelete()->comment('Station innerhalb dieses Mandanten.');
            $table->primary(['employee_id', 'station_id']);
        });
        Schema::connection('tenant')->create('audit_events', function (Blueprint $table): void {
            $table->id()->comment('Fachliche Ereignisreferenz innerhalb des Mandanten.');
            $table->string('actor_id')->nullable()->comment('Interner Akteur ohne unnötige Personendaten.');
            $table->string('action', 100)->comment('Fachlicher Aktionsschlüssel.');
            $table->string('subject_id')->nullable()->comment('Betroffenes Fachobjekt.');
            $table->timestamp('occurred_at')->useCurrent()->comment('Zeitpunkt in UTC.');
        });
    }

    /** Entfernt nur die Fachgrundtabellen im ausdrücklich aktiven Tenant-Schema. */
    public function down(): void
    {
        foreach (['audit_events', 'employee_station_assignments', 'employees', 'stations'] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }
    }
};
