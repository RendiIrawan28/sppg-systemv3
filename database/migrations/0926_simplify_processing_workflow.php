<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processing_batches')) {
            DB::table('processing_batches')
                ->where('state', 'handed_over')
                ->update(['state' => 'completed']);
        }

        if (Schema::hasTable('portioning_sessions')
            && Schema::hasColumn('portioning_sessions', 'processing_handover_id')) {
            if (Schema::hasIndex(
                'portioning_sessions',
                'portioning_sessions_processing_handover_id_index',
            )) {
                Schema::table('portioning_sessions', function (Blueprint $table): void {
                    $table->dropIndex('portioning_sessions_processing_handover_id_index');
                });
            }
            Schema::table('portioning_sessions', function (Blueprint $table): void {
                $table->dropColumn('processing_handover_id');
            });
        }

        Schema::dropIfExists('processing_handovers');
        Schema::dropIfExists('processing_steps');
        Schema::dropIfExists('processing_deviations');
        Schema::dropIfExists('processing_batch_destinations');

        if (! Schema::hasTable('processing_returns')) {
            Schema::create('processing_returns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sppg_unit_id')->index();
                $table->foreignId('processing_batch_id')->index();
                $table->foreignId('processing_material_usage_id')->index();
                $table->foreignId('source_inventory_lot_id')->nullable()->index();
                $table->foreignId('destination_inventory_lot_id')->nullable()->index();
                $table->foreignId('ingredient_id')->index();
                $table->string('return_number', 100)->unique();
                $table->date('return_date')->index();
                $table->string('ingredient_name_snapshot');
                $table->string('unit_snapshot', 80);
                $table->decimal('requested_quantity', 14, 4);
                $table->decimal('actual_quantity', 14, 4)->nullable();
                $table->string('condition_status', 30)->default('good');
                $table->string('warehouse_disposition', 30)->nullable();
                $table->text('reason');
                $table->string('photo_path')->nullable();
                $table->text('warehouse_notes')->nullable();
                $table->string('status', 40)->default('waiting_warehouse_verification')->index();
                $table->foreignId('returned_by')->index();
                $table->timestamp('submitted_at');
                $table->foreignId('verified_by')->nullable()->index();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_returns');
    }
};
