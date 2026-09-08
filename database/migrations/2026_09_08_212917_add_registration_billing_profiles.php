<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /** Ergänzt Rechnungsanschrift und eine dauerhafte Trennung lokaler Testregistrierungen von echten Verträgen. */
    public function up(): void
    {
        Schema::connection('central')->create('tenant_billing_profiles', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained()->restrictOnDelete()->comment('Rechnungsanschrift des Betreibers; Firmenname steht in der Registry.');
            $table->string('street')->comment('Straße und Hausnummer der Rechnungsanschrift.');
            $table->string('postal_code', 20)->comment('Postleitzahl als Text einschließlich führender Nullen.');
            $table->string('city')->comment('Ort der Rechnungsanschrift.');
            $table->char('country_code', 2)->comment('Zweistelliger Ländercode.');
            $table->string('phone', 50)->nullable()->comment('Optional angegebene geschäftliche Telefonnummer.');
            $table->string('vat_id', 32)->nullable()->comment('Optionale Umsatzsteuer-ID, noch ohne externe Prüfung.');
            $table->timestamps();
        });
        Schema::connection('central')->table('registration_requests', function (Blueprint $table): void {
            $table->foreignId('billing_settings_version_id')->nullable()->constrained('billing_settings_versions')->restrictOnDelete()->comment('Herkunft des Preisstands; bestehende Aufträge besitzen bereits einen Preis-Snapshot.');
            $table->boolean('is_test_registration')->default(false)->comment('Lokaler Testauftrag ohne Vertragsannahme und ohne Freigabe für echte Abrechnung.');
        });
        Schema::connection('central')->table('subscriptions', function (Blueprint $table): void {
            $table->boolean('is_test_registration')->default(false)->comment('Testabos dürfen später nicht automatisch real abgerechnet werden.');
        });
    }

    /** Entfernt nur die mit dieser Migration hinzugefügten Felder und die Rechnungsanschriftentabelle. */
    public function down(): void
    {
        Schema::connection('central')->table('subscriptions', fn (Blueprint $table) => $table->dropColumn('is_test_registration'));
        Schema::connection('central')->table('registration_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_settings_version_id');
            $table->dropColumn('is_test_registration');
        });
        Schema::connection('central')->dropIfExists('tenant_billing_profiles');
    }
};
