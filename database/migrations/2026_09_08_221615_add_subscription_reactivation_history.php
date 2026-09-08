<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /** Bewahrt jeden Kündigungsvorgang und ergänzt dessen einmalige Rücknahme als eigenen Nachweis. */
    public function up(): void
    {
        Schema::connection('central')->table('subscription_cancellations', function (Blueprint $table): void {
            $table->index('subscription_id', 'subscription_cancellations_history_index');
        });
        Schema::connection('central')->table('subscription_cancellations', function (Blueprint $table): void {
            $table->dropUnique('subscription_cancellations_subscription_id_unique');
        });
        Schema::connection('central')->create('subscription_reactivations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cancellation_id')->unique()->constrained('subscription_cancellations')->restrictOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('reactivated_by')->constrained('owners')->restrictOnDelete();
            $table->timestamp('reactivated_at');
            $table->boolean('was_ended')->comment('Unterscheidet Rücknahme vor dem Ende und Reaktivierung danach.');
        });
    }

    /** Verhindert ein Zurückrollen, das bereits entstandene Vertragsnachweise verlieren würde. */
    public function down(): void
    {
        if (DB::connection('central')->table('subscription_reactivations')->exists()
            || DB::connection('central')->table('subscription_cancellations')->groupBy('subscription_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Die Reaktivierungshistorie muss erhalten bleiben; Rückmigration ist nicht möglich.');
        }
        Schema::connection('central')->dropIfExists('subscription_reactivations');
        Schema::connection('central')->table('subscription_cancellations', function (Blueprint $table): void {
            $table->unique('subscription_id');
            $table->dropIndex('subscription_cancellations_history_index');
        });
    }
};
