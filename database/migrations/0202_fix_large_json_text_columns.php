<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These fields store JSON snapshots/breakdowns or generated long text.
     * They must not be VARCHAR(255), because menu cycle snapshots and
     * nutrition calculation details can easily exceed that length.
     */
    private array $jsonColumns = [
        'menu_cycles' => ['beneficiary_breakdown'],
        'menus' => ['snapshot_payload'],
        'nutrition_requirement_plans' => ['portion_breakdown'],
        'nutrition_requirement_items' => ['calculation_breakdown'],

        // Existing snapshot/metadata fields kept here for safety when a clean rebuild used string columns.
        'menu_approvals' => ['snapshot'],
        'menu_day_revision_requests' => ['snapshot'],
        'nutrition_workflow_histories' => ['snapshot'],
        'head_approval_tasks' => ['metadata'],
        'head_executive_reports' => [
            'division_summary',
            'nutrition_summary',
            'approval_summary',
            'incident_summary',
        ],
        'cleaning_areas' => ['default_checklist'],
        'menu_allergen_summaries' => ['source_ingredients'],
    ];

    private array $longTextColumns = [
        'nutrition_requirement_items' => ['recipe_components'],
        'field_incidents' => ['evidence_paths'],
    ];

    public function up(): void
    {
        // JSON/TEXT values are already unbounded in SQLite. MODIFY is MySQL syntax.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->jsonColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // LONGTEXT is used instead of JSON for compatibility with both MySQL and MariaDB/XAMPP.
                // Laravel casts can still encode/decode it as array/json safely.
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` LONGTEXT NULL");
            }
        }

        foreach ($this->longTextColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` LONGTEXT NULL");
            }
        }
    }

    public function down(): void
    {
        // Intentionally not reverting to VARCHAR because data may already exceed 255 characters.
    }
};
