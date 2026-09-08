<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /** Bestehende Benutzer behalten die bisherige Petrol-Darstellung. */
    public function up(): void
    {
        Schema::connection('central')->table('owners', function (Blueprint $table): void {
            $table->string('color_scheme', 32)->default('petrol')->comment('Persönliche freigegebene Farbpalette, unabhängig von anderen Benutzern.');
        });
    }

    /** Entfernt ausschließlich die persönliche Farbauswahl. */
    public function down(): void
    {
        Schema::connection('central')->table('owners', fn (Blueprint $table) => $table->dropColumn('color_scheme'));
    }
};
