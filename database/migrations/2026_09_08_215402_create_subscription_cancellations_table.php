<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /** Hält Erklärung und wirksamen Termin unverändert fest; pro Vertrag zunächst genau eine Kündigung ohne Widerrufsfunktion. */
    public function up(): void
    {
        Schema::connection('central')->create('subscription_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained('subscriptions')->restrictOnDelete()->comment('Eindeutiger gekündigter Stationsvertrag.');
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete()->comment('Owner-Mandant zum Zeitpunkt der Kündigung.');
            $table->foreignId('requested_by')->constrained('owners')->restrictOnDelete()->comment('Owner, der die Kündigung bestätigt hat.');
            $table->timestamp('requested_at')->comment('Zeitpunkt der Kündigungserklärung in UTC.');
            $table->timestamp('effective_at')->comment('Bestätigtes Ende von Trial oder laufender Monatsperiode in UTC.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_cancellations');
    }
};
