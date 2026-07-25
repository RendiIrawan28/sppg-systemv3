<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('warehouse_withdrawal_id')->unique();
            $table->string('session_number')->index();
            $table->date('preparation_date')->index();
            $table->string('purpose_reference')->nullable();
            $table->string('state', 30)->default('planned')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('petugas_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('division_approved_by')->nullable();
            $table->timestamp('division_approved_at')->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('preparation_session_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->index();
            $table->foreignId('warehouse_withdrawal_item_id')->nullable();
            $table->foreignId('ingredient_id')->index();
            $table->foreignId('inventory_lot_id')->nullable()->index();
            $table->string('ingredient_name_snapshot');
            $table->decimal('received_weight_kg', 14, 4);
            $table->decimal('clean_weight_kg', 14, 4)->nullable();
            $table->decimal('waste_weight_kg', 14, 4)->nullable();
            $table->string('process_method')->nullable();
            $table->decimal('thawing_temperature_celsius', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('preparation_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->index();
            $table->string('step_name');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('preparation_deviations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->index();
            $table->timestamp('detected_at');
            $table->string('severity', 20)->default('low');
            $table->text('description');
            $table->text('corrective_action')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('preparation_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->unique();
            $table->string('received_by_name');
            $table->decimal('total_clean_weight_kg', 14, 4);
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('handed_over_by');
            $table->timestamp('handed_over_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_handovers');
        Schema::dropIfExists('preparation_deviations');
        Schema::dropIfExists('preparation_steps');
        Schema::dropIfExists('preparation_session_items');
        Schema::dropIfExists('preparation_sessions');
    }
};
