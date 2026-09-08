<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wiederaufnehmbare Bereitstellung und unveränderliche Startkonditionen des Stationsabos. */
return new class extends Migration
{
    protected $connection = 'central';

    /** Trennt Onboarding-Arbeitsdaten, Laufhistorie und kaufmännische Vertragswerte. */
    public function up(): void
    {
        Schema::connection('central')->create('registration_requests', function (Blueprint $table): void {
            $table->id()->comment('Interner Registrierungsauftrag.');
            $table->foreignUuid('tenant_id')->unique()->constrained()->restrictOnDelete()->comment('Genau ein initialer Auftrag je Mandant.');
            $table->uuid('station_id')->unique()->comment('Vorab erzeugte stabile ID der ersten Station.');
            $table->uuid('employee_id')->unique()->comment('Vorab erzeugte stabile Chef-ID für idempotente Wiederholungen.');
            $table->string('station_name')->comment('Gewünschter Stationsname.');
            $table->string('station_street')->comment('Straße und Hausnummer der ersten Station.');
            $table->string('station_postal_code', 20)->comment('Postleitzahl als Text.');
            $table->string('station_city')->comment('Ort der ersten Station.');
            $table->char('station_country_code', 2)->comment('ISO-Ländercode der Stationsanschrift.');
            $table->unsignedInteger('gross_cents')->comment('Bei Registrierung angenommener monatlicher Bruttopreis pro Station.');
            $table->unsignedSmallInteger('tax_basis_points')->comment('Angenommener Steuersatz in Basispunkten; 1900 entspricht 19 Prozent.');
            $table->timestamp('completed_at')->nullable()->comment('Zeitpunkt der erfolgreichen Bereitstellung.');
            $table->timestamps();
        });
        Schema::connection('central')->create('provisioning_runs', function (Blueprint $table): void {
            $table->id()->comment('Einzelner Bereitstellungsversuch.');
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete()->comment('Betroffener Mandant.');
            $table->string('status', 32)->comment('running, succeeded oder failed.');
            $table->string('step', 32)->comment('Zuletzt begonnener technischer Schritt.');
            $table->string('error_code')->nullable()->comment('Bereinigter Fehlercode, niemals SQL oder Zugangsdaten.');
            $table->timestamp('started_at')->comment('Beginn des Versuchs in UTC.');
            $table->timestamp('finished_at')->nullable()->comment('Abschluss des Versuchs in UTC.');
        });
        Schema::connection('central')->create('subscriptions', function (Blueprint $table): void {
            $table->id()->comment('Interne Vertragsreferenz.');
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete()->comment('Vertraglicher Mandant.');
            $table->uuid('station_id')->unique()->comment('Stabile Stationsreferenz ohne DB-übergreifenden Fremdschlüssel.');
            $table->string('status', 32)->comment('Stationsbezogener Abostatus.');
            $table->unsignedInteger('gross_cents')->comment('Historisch angenommener monatlicher Bruttopreis.');
            $table->unsignedSmallInteger('tax_basis_points')->comment('Historisch angenommener Steuersatz.');
            $table->char('currency', 3)->default('EUR')->comment('Vertragswährung.');
            $table->timestamp('trial_started_at')->comment('Erfolgreiche Bereitstellung nach bestätigter E-Mail.');
            $table->timestamp('trial_ends_at')->comment('Trial-Ende exakt 30 Tage nach Bereitstellung.');
            $table->timestamp('billing_anchor_at')->comment('Anker für monatliche Vorauszahlungsperioden ab Trial-Ende.');
            $table->timestamp('cancelled_at')->nullable()->comment('Zeitpunkt der Kündigungserklärung.');
            $table->timestamp('ends_at')->nullable()->comment('Wirksames Vertragsende am Ende der laufenden Monatsperiode.');
            $table->timestamps();
        });
    }

    /** Entfernt nur die zugehörigen zentralen Tabellen in Abhängigkeitsreihenfolge. */
    public function down(): void
    {
        foreach (['subscriptions', 'provisioning_runs', 'registration_requests'] as $table) {
            Schema::connection('central')->dropIfExists($table);
        }
    }
};
