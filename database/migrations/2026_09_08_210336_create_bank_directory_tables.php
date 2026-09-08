<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Versionierte öffentliche Bundesbank-Stammdaten; enthält keine Kundenkontonummern. */
return new class extends Migration
{
    protected $connection = 'central';

    /** Vollständige Importsätze bleiben über ihre Gültigkeit und Dateiprüfsumme nachvollziehbar. */
    public function up(): void
    {
        Schema::connection('central')->create('bank_directory_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->unsignedInteger('row_count');
            $table->timestamp('created_at');
            $table->unique(['sha256', 'valid_from', 'valid_until']);
        });
        Schema::connection('central')->create('bank_directory_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('bank_directory_imports')->cascadeOnDelete();
            $table->char('record_number', 6)->comment('Bundesbank-Datensatznummer, auch für Filialen eindeutig.');
            $table->char('bank_code', 8)->index();
            $table->char('record_type', 1)->comment('1: bankleitzahlführend, 2: weiterer Datensatz.');
            $table->string('name');
            $table->char('postal_code', 5);
            $table->string('city');
            $table->string('short_name');
            $table->char('pan', 5);
            $table->string('bic', 11);
            $table->char('check_method', 2)->comment('Kontonummer-Prüfziffermethode, ausdrücklich keine IBAN-Regel.');
            $table->char('change_code', 1);
            $table->boolean('deletion_planned');
            $table->char('successor_bank_code', 8);
            $table->unique(['import_id', 'record_number']);
        });
    }

    /** Entfernt nur diesen öffentlichen Bankenstamm. */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('bank_directory_entries');
        Schema::connection('central')->dropIfExists('bank_directory_imports');
    }
};
