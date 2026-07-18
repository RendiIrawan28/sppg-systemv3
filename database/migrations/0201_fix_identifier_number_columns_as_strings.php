<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns ending in _number are often document/identity codes, not counters.
     * They must be VARCHAR so values like NIK/NISN/document numbers are not truncated
     * and do not overflow MySQL integer columns.
     */
    private array $identifierColumns = [
        'beneficiary_period_members' => ['identity_number'],
        'beneficiary_periods' => ['document_number'],

        'nutrition_requirement_plans' => ['plan_number'],
        'nutrition_daily_reports' => ['report_number'],

        'field_distribution_plans' => ['plan_number'],
        'field_daily_reports' => ['report_number'],

        'procurement_requests' => ['request_number'],
        'stock_receipts' => ['receipt_number'],
        'stock_movements' => ['reference_number', 'supplier_batch_number'],
        'stock_receipt_items' => ['supplier_batch_number'],
        'preparation_material_handovers' => ['handover_number'],
        'preparation_material_handover_items' => ['supplier_batch_number'],

        'processing_batches' => ['batch_number'],
        'portioning_sessions' => ['session_number'],
        'distribution_runs' => ['run_number'],

        'washing_sessions' => ['session_number'],
        'washing_chemical_usages' => ['batch_number'],
        'cleaning_sessions' => ['session_number'],
        'cleaning_chemical_usages' => ['batch_number'],
        'waste_handover_reports' => ['report_number'],

        'head_approval_tasks' => ['document_number'],
        'head_executive_reports' => ['report_number'],
    ];

    public function up(): void
    {
        foreach ($this->identifierColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(80) NULL");
            }
        }
    }

    public function down(): void
    {
        // Intentionally not reverting to integer columns.
        // These fields are identifiers/document numbers, not numeric counters.
    }
};
