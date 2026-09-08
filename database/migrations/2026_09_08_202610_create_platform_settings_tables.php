<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Zentrale Einstellungsversionen; Bankdaten und SMTP-Geheimnisse werden durch den Dienst verschlüsselt. */
return new class extends Migration
{
    protected $connection = 'central';

    /** Separate Tabellen je Zuständigkeit; eine Sperrzeile serialisiert auch die erstmalige Anlage. */
    public function up(): void
    {
        $schema = Schema::connection('central');
        $schema->create('platform_settings_lock', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary()->comment('Einzige Sperrzeile für atomare Einstellungsänderungen.');
        });
        DB::connection('central')->table('platform_settings_lock')->insert(['id' => 1]);
        $schema->create('billing_settings_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('gross_cents')->comment('Monatlicher Stationspreis in EUR-Cent für neue Verträge.');
            $table->unsignedInteger('tax_basis_points')->default(1900)->comment('Bestätigter Steuersatz: 19 Prozent.');
            $table->unsignedSmallInteger('prenotification_days')->comment('Kalendertage; benötigt entsprechende Vertragsvereinbarung.');
            $table->foreignId('created_by')->constrained('super_admins');
            $table->timestamp('created_at');
        });
        $schema->create('creditor_profile_versions', function (Blueprint $table): void {
            $table->id();
            foreach (['company_name', 'street', 'postal_code', 'city', 'country_code', 'creditor_identifier', 'account_holder'] as $field) {
                $table->string($field)->comment('Versionierter Gläubigerstammdatenwert.');
            }
            $table->text('iban')->comment('Verschlüsselte Gläubiger-IBAN.');
            $table->string('bic', 11)->nullable();
            $table->foreignId('created_by')->constrained('super_admins');
            $table->timestamp('created_at');
        });
        $schema->create('platform_smtp_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision')->comment('Optimistische Sperre für konkurrierende Änderungen.');
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 16)->comment('STARTTLS oder implizites TLS; noch kein aktiver Versand.');
            $table->string('username');
            $table->text('password')->comment('Verschlüsseltes Passwort; niemals an den Browser zurückgeben.');
            $table->string('from_address');
            $table->string('from_name');
            $table->foreignId('updated_by')->constrained('super_admins');
            $table->timestamps();
        });
        $schema->create('platform_fints_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision')->comment('Optimistische Sperre für konkurrierende Änderungen.');
            $table->string('bank_name');
            $table->string('bank_code', 8);
            $table->string('endpoint', 2048)->comment('HTTPS-Endpunkt; hier erfolgt kein Verbindungsaufbau.');
            $table->string('product_id')->comment('Eigene FinTS-Produktregistrierungsnummer.');
            $table->foreignId('updated_by')->constrained('super_admins');
            $table->timestamps();
        });
    }

    /** Entfernt ausschließlich die Tabellen dieses Einstellungsschritts. */
    public function down(): void
    {
        foreach (['platform_fints_settings', 'platform_smtp_settings', 'creditor_profile_versions', 'billing_settings_versions', 'platform_settings_lock'] as $table) {
            Schema::connection('central')->dropIfExists($table);
        }
    }
};
