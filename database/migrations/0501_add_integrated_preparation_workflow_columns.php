<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function shortIndexName(string $table, string $column): string
    {
        return substr('idx_'.md5($table.'_'.$column), 0, 32);
    }

    public function up(): void
    {
        if (Schema::hasTable('stock_receipt_items')) {
            Schema::table('stock_receipt_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_receipt_items', 'ordered_quantity')) {
                    $table->decimal('ordered_quantity', 14, 4)->nullable()->after('unit_snapshot');
                }
                if (! Schema::hasColumn('stock_receipt_items', 'received_quantity')) {
                    $table->decimal('received_quantity', 14, 4)->nullable()->after('ordered_quantity');
                }
                if (! Schema::hasColumn('stock_receipt_items', 'accepted_quantity')) {
                    $table->decimal('accepted_quantity', 14, 4)->nullable()->after('received_quantity');
                }
                if (! Schema::hasColumn('stock_receipt_items', 'rejected_quantity')) {
                    $table->decimal('rejected_quantity', 14, 4)->nullable()->after('accepted_quantity');
                }
            });
        }

        if (Schema::hasTable('preparation_material_handovers')) {
            Schema::table('preparation_material_handovers', function (Blueprint $table): void {
                if (! Schema::hasColumn('preparation_material_handovers', 'field_distribution_plan_id')) {
                    $table->unsignedBigInteger('field_distribution_plan_id')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'field_distribution_plan_id'))->after('sppg_unit_id');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'processing_batch_id')) {
                    $table->unsignedBigInteger('processing_batch_id')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'processing_batch_id'))->after('field_distribution_plan_id');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'inspected_by')) {
                    $table->unsignedBigInteger('inspected_by')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'inspected_by'))->after('received_at');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'inspected_at')) {
                    $table->timestamp('inspected_at')->nullable()->after('inspected_by');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'prepared_by')) {
                    $table->unsignedBigInteger('prepared_by')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'prepared_by'))->after('inspected_at');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'prepared_at')) {
                    $table->timestamp('prepared_at')->nullable()->after('prepared_by');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'waste_recorded_by')) {
                    $table->unsignedBigInteger('waste_recorded_by')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'waste_recorded_by'))->after('prepared_at');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'waste_recorded_at')) {
                    $table->timestamp('waste_recorded_at')->nullable()->after('waste_recorded_by');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'handed_over_to_processing_by')) {
                    $table->unsignedBigInteger('handed_over_to_processing_by')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'handed_over_to_processing_by'))->after('waste_recorded_at');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'handed_over_to_processing_at')) {
                    $table->timestamp('handed_over_to_processing_at')->nullable()->after('handed_over_to_processing_by');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'completed_by')) {
                    $table->unsignedBigInteger('completed_by')->nullable()->index($this->shortIndexName('preparation_material_handovers', 'completed_by'))->after('handed_over_to_processing_at');
                }
                if (! Schema::hasColumn('preparation_material_handovers', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('completed_by');
                }
            });
        }

        if (Schema::hasTable('preparation_material_handover_items')) {
            Schema::table('preparation_material_handover_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('preparation_material_handover_items', 'requested_quantity')) {
                    $table->decimal('requested_quantity', 14, 4)->nullable()->after('unit_snapshot');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'handed_over_quantity')) {
                    $table->decimal('handed_over_quantity', 14, 4)->nullable()->after('requested_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'received_quantity')) {
                    $table->decimal('received_quantity', 14, 4)->nullable()->after('handed_over_quantity_kg');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'good_quantity')) {
                    $table->decimal('good_quantity', 14, 4)->nullable()->after('received_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'moderate_quantity')) {
                    $table->decimal('moderate_quantity', 14, 4)->nullable()->after('good_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'damaged_quantity')) {
                    $table->decimal('damaged_quantity', 14, 4)->nullable()->after('moderate_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'inspection_status')) {
                    $table->string('inspection_status', 80)->nullable()->after('damaged_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'inspection_notes')) {
                    $table->text('inspection_notes')->nullable()->after('inspection_status');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'inspection_photo_path')) {
                    $table->string('inspection_photo_path')->nullable()->after('inspection_notes');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'prepared_quantity')) {
                    $table->decimal('prepared_quantity', 14, 4)->nullable()->after('inspection_photo_path');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'preparation_notes')) {
                    $table->text('preparation_notes')->nullable()->after('prepared_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'waste_type')) {
                    $table->string('waste_type')->nullable()->after('preparation_notes');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'waste_quantity')) {
                    $table->decimal('waste_quantity', 14, 4)->nullable()->after('waste_type');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'waste_unit_snapshot')) {
                    $table->string('waste_unit_snapshot', 80)->nullable()->after('waste_quantity');
                }
                if (! Schema::hasColumn('preparation_material_handover_items', 'waste_notes')) {
                    $table->text('waste_notes')->nullable()->after('waste_unit_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('preparation_material_handover_items')) {
            Schema::table('preparation_material_handover_items', function (Blueprint $table): void {
                foreach ([
                    'waste_notes', 'waste_unit_snapshot', 'waste_quantity', 'waste_type', 'preparation_notes',
                    'prepared_quantity', 'inspection_photo_path', 'inspection_notes', 'inspection_status',
                    'damaged_quantity', 'moderate_quantity', 'good_quantity', 'received_quantity',
                    'handed_over_quantity', 'requested_quantity',
                ] as $column) {
                    if (Schema::hasColumn('preparation_material_handover_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('preparation_material_handovers')) {
            Schema::table('preparation_material_handovers', function (Blueprint $table): void {
                foreach ([
                    'completed_at', 'completed_by', 'handed_over_to_processing_at', 'handed_over_to_processing_by',
                    'waste_recorded_at', 'waste_recorded_by', 'prepared_at', 'prepared_by', 'inspected_at',
                    'inspected_by', 'processing_batch_id', 'field_distribution_plan_id',
                ] as $column) {
                    if (Schema::hasColumn('preparation_material_handovers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_receipt_items')) {
            Schema::table('stock_receipt_items', function (Blueprint $table): void {
                foreach (['rejected_quantity', 'accepted_quantity', 'received_quantity', 'ordered_quantity'] as $column) {
                    if (Schema::hasColumn('stock_receipt_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
