<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_handovers', function (Blueprint $table): void {
            $table->unsignedBigInteger('processing_batch_id')->nullable()->after('preparation_session_id')->index();
        });
        Schema::table('processing_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('division_approved_by')->nullable()->after('submitted_at')->index();
            $table->timestamp('division_approved_at')->nullable()->after('division_approved_by');
        });
        Schema::table('processing_material_usages', function (Blueprint $table): void {
            $table->string('source_type', 50)->nullable()->after('processing_batch_id')->index();
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type')->index();
            $table->unsignedBigInteger('source_item_id')->nullable()->after('source_id')->index();
            $table->unsignedBigInteger('inventory_lot_id')->nullable()->after('ingredient_id')->index();
            $table->string('source_reference')->nullable()->after('unit_name');
            $table->string('condition_status', 30)->default('good')->after('source_reference');
            $table->unsignedBigInteger('received_by')->nullable()->after('condition_status')->index();
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->unique(['processing_batch_id', 'source_type', 'source_item_id'], 'processing_usage_source_unique');
        });
        Schema::table('processing_steps', function (Blueprint $table): void {
            $table->string('category', 50)->default('process')->after('processing_batch_id')->index();
            $table->boolean('is_mandatory')->default(true)->after('step_name');
            $table->string('result', 30)->default('pending')->after('is_mandatory')->index();
            $table->unsignedBigInteger('checked_by')->nullable()->after('completed_at')->index();
            $table->timestamp('checked_at')->nullable()->after('checked_by');
        });
        Schema::table('processing_deviations', function (Blueprint $table): void {
            $table->text('immediate_action')->nullable()->after('description');
            $table->string('photo_path')->nullable()->after('corrective_action');
            $table->unsignedBigInteger('reported_by')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('processing_deviations', fn (Blueprint $table) => $table->dropColumn(['immediate_action', 'photo_path', 'reported_by']));
        Schema::table('processing_steps', fn (Blueprint $table) => $table->dropColumn(['category', 'is_mandatory', 'result', 'checked_by', 'checked_at']));
        Schema::table('processing_material_usages', function (Blueprint $table): void {
            $table->dropUnique('processing_usage_source_unique');
            $table->dropColumn(['source_type', 'source_id', 'source_item_id', 'inventory_lot_id', 'source_reference', 'condition_status', 'received_by', 'received_at']);
        });
        Schema::table('processing_batches', fn (Blueprint $table) => $table->dropColumn(['division_approved_by', 'division_approved_at']));
        Schema::table('preparation_handovers', fn (Blueprint $table) => $table->dropColumn('processing_batch_id'));
    }
};
