<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_handovers', function (Blueprint $table): void {
            $table->unsignedBigInteger('portioning_session_id')->nullable()->after('processing_batch_id')->index();
        });
        Schema::table('portioning_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('processing_handover_id')->nullable()->after('processing_batch_id')->index();
            $table->decimal('received_output_quantity', 14, 3)->nullable()->after('actual_large_portions');
            $table->string('received_output_unit', 80)->nullable()->after('received_output_quantity');
            $table->decimal('received_temperature_celsius', 6, 2)->nullable()->after('received_output_unit');
            $table->unsignedBigInteger('received_by')->nullable()->after('received_temperature_celsius')->index();
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->text('input_variance_notes')->nullable()->after('notes');
            $table->unsignedBigInteger('division_approved_by')->nullable()->after('submitted_at')->index();
            $table->timestamp('division_approved_at')->nullable()->after('division_approved_by');
        });
        Schema::table('portioning_deviations', function (Blueprint $table): void {
            $table->text('immediate_action')->nullable()->after('description');
            $table->string('photo_path')->nullable()->after('corrective_action');
            $table->unsignedBigInteger('reported_by')->nullable()->after('status')->index();
        });

        Schema::create('portioning_supplies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portioning_session_id')->index();
            $table->string('source_type', 50)->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->unsignedBigInteger('source_item_id')->index();
            $table->unsignedBigInteger('ingredient_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_lot_id')->nullable()->index();
            $table->string('supply_name');
            $table->decimal('quantity', 14, 4);
            $table->string('unit_name', 80);
            $table->string('source_reference')->nullable();
            $table->string('condition_status', 30)->default('good');
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['portioning_session_id', 'source_type', 'source_item_id'], 'portioning_supply_source_unique');
        });
        Schema::create('portioning_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portioning_session_id')->index();
            $table->string('category', 50)->index();
            $table->string('item_name');
            $table->boolean('is_mandatory')->default(true);
            $table->string('result', 30)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('checked_by')->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('portioning_temperature_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portioning_session_id')->index();
            $table->timestamp('checked_at')->index();
            $table->string('checkpoint', 40)->index();
            $table->decimal('temperature_celsius', 6, 2);
            $table->decimal('minimum_temperature', 6, 2)->nullable();
            $table->decimal('maximum_temperature', 6, 2)->nullable();
            $table->boolean('is_within_limit')->default(true);
            $table->text('corrective_action')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('measured_by')->nullable()->index();
            $table->string('measured_name_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portioning_temperature_logs');
        Schema::dropIfExists('portioning_checklist_items');
        Schema::dropIfExists('portioning_supplies');
        Schema::table('portioning_deviations', fn (Blueprint $table) => $table->dropColumn(['immediate_action', 'photo_path', 'reported_by']));
        Schema::table('portioning_sessions', fn (Blueprint $table) => $table->dropColumn([
            'processing_handover_id', 'received_output_quantity', 'received_output_unit', 'received_temperature_celsius',
            'received_by', 'received_at', 'input_variance_notes', 'division_approved_by', 'division_approved_at',
        ]));
        Schema::table('processing_handovers', fn (Blueprint $table) => $table->dropColumn('portioning_session_id'));
    }
};
