<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    private function shortIndexName(string $tableName, string $column): string
    {
        return substr('idx_' . md5($tableName . '_' . $column), 0, 32);
    }

    private function addSmartColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (str_ends_with($column, '_id') || in_array($column, [
            'created_by', 'updated_by', 'submitted_by', 'approved_by', 'activated_by', 'verified_by', 'ordered_by', 'received_by', 'handed_over_by',
            'actor_id', 'user_id', 'petugas_id', 'supervisor_id', 'resolved_by', 'processed_by', 'prepared_by', 'requested_by', 'decided_by',
            'price_finalized_by', 'actual_data_synced_by', 'checked_by', 'measured_by', 'received_by_user_id', 'source_id', 'location_id', 'destination_id',
        ], true)) {
            $table->unsignedBigInteger($column)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if ($column === 'uuid') {
            $table->uuid($column)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_ends_with($column, '_date') || in_array($column, [
            'date_from', 'date_to', 'start_date', 'end_date', 'effective_from', 'effective_until', 'expiry_date', 'expired_date', 'needed_date', 'holiday_date', 'birth_date',
        ], true)) {
            $table->date($column)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_ends_with($column, '_time') || in_array($column, ['receiving_time', 'preferred_delivery_time'], true)) {
            $table->time($column)->nullable();
            return;
        }

        if (str_ends_with($column, '_path') || str_ends_with($column, '_url') || in_array($column, [
            'photo_path', 'legacy_photo_url', 'signature_path', 'handover_photo_path', 'attachment_path', 'file_path', 'logo_path',
        ], true)) {
            $table->string($column)->nullable();
            return;
        }

        if (str_ends_with($column, '_at') || in_array($column, [
            'generated_at', 'captured_at', 'occurred_at', 'detected_at', 'checked_at', 'measured_at', 'used_at', 'found_at', 'due_at',
        ], true)) {
            $table->timestamp($column)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if ($column === 'is_active') {
            $table->boolean($column)->nullable()->default(true)->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_starts_with($column, 'is_') || str_starts_with($column, 'has_') || in_array($column, ['use_default', 'contamination_risk'], true)) {
            $table->boolean($column)->nullable()->default(false)->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_ends_with($column, '_breakdown') || str_ends_with($column, '_payload') || in_array($column, [
            'beneficiary_breakdown', 'snapshot_payload', 'portion_breakdown', 'calculation_breakdown', 'recipe_components', 'evidence_paths',
        ], true)) {
            $table->longText($column)->nullable();
            return;
        }

        if (str_ends_with($column, '_paths') || in_array($column, [
            'metadata', 'snapshot', 'errors', 'default_checklist', 'source_ingredients', 'division_summary', 'nutrition_summary', 'approval_summary', 'incident_summary',
            'operational_metrics', 'attachment_paths', 'tags', 'options', 'failed_job_ids', 'abilities',
        ], true)) {
            $table->json($column)->nullable();
            return;
        }

        if (str_contains($column, 'latitude') || str_contains($column, 'longitude')) {
            $table->decimal($column, 11, 7)->nullable();
            return;
        }

        // IMPORTANT: textual columns must be detected before decimal/integer patterns.
        // Examples that previously broke: price_status, batch_type, target_output_unit, phone.
        if (str_contains($column, 'status') || str_contains($column, 'state') || str_ends_with($column, '_type') || str_ends_with($column, '_code') || in_array($column, ['code', 'slug'], true)) {
            $table->string($column, 80)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (preg_match('/(^identity_number$|_number$|phone|telp|whatsapp|npsn|nisn|nik|nib|npwp|external_id|registration_number|serial_number|plate_number)/i', $column)) {
            $table->string($column, 80)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_ends_with($column, '_unit') || str_ends_with($column, '_unit_name') || in_array($column, [
            'unit', 'unit_name', 'target_output_unit', 'actual_output_unit', 'measurement_unit', 'unit_snapshot',
        ], true)) {
            $table->string($column, 80)->nullable();
            return;
        }

        // Kolom teks wajib diperiksa sebelum pola angka. Kata "preparation"
        // mengandung "ratio", sehingga preparation_notes akan keliru menjadi
        // DECIMAL apabila pemeriksaan numeric dijalankan lebih dahulu.
        if (preg_match('/(notes|description|address|summary|action|reason|remarks|instruction|evaluation|obstacles|recommendations|resolution|root_cause|caption|payload|exception|body|content|complaints)/', $column)) {
            $table->text($column)->nullable();
            return;
        }

        if (preg_match('/(quantity|weight|grams|kg|unit_price|total_price|amount|percent|temperature|ph|ppm|multiplier|rate|score|ratio|minimum|maximum|estimated|accepted|rejected|buffer)/', $column)) {
            $table->decimal($column, 14, 4)->nullable()->default(null);
            return;
        }

        if (preg_match('/(count|total|rows|year|minutes|duration|sequence|sort_order|attempts|containers|portions|beneficiaries|members|destinations|records|items|jobs|sample)/', $column)) {
            $table->unsignedInteger($column)->nullable()->default(0)->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (str_ends_with($column, '_email') || $column === 'email') {
            $table->string($column)->nullable()->index($this->shortIndexName($tableName, $column));
            return;
        }

        $table->string($column)->nullable();
    }

    private function createSmartTable(string $tableName, array $columns, bool $softDeletes = false): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $columns, $softDeletes): void {
            $table->id();

            foreach ($columns as $column) {
                if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $this->addSmartColumn($table, $tableName, $column);
            }

            $table->timestamps();

            if ($softDeletes) {
                $table->softDeletes();
            }
        });
    }

    public function up(): void
    {
        $tables = [
            'allergens' => [
                'soft_deletes' => false,
                'columns' => ['code', 'name', 'description', 'sort_order', 'is_active'],
            ],
            'ingredient_allergen' => [
                'soft_deletes' => false,
                'columns' => ['ingredient_id', 'allergen_id', 'contamination_risk', 'notes'],
            ],
            'ingredient_nutritions' => [
                'soft_deletes' => false,
                'columns' => ['ingredient_id', 'source', 'notes', 'nutrition_component_id', 'value_per_100g'],
            ],
            'ingredients' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'measurement_unit_id', 'code', 'name', 'category', 'description', 'photo_path', 'is_active', 'edible_portion_percent'],
            ],
            'measurement_units' => [
                'soft_deletes' => false,
                'columns' => ['code', 'name', 'symbol', 'unit_type', 'is_active', 'to_base_factor'],
            ],
            'menu_acceptance_evaluations' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'evaluation_date', 'menu_id', 'field_distribution_plan_id', 'distribution_run_id', 'location_type', 'location_id', 'location_name_snapshot', 'respondent_count', 'served_portions', 'accepted_portions', 'leftover_portions', 'color_score', 'aroma_score', 'taste_score', 'texture_score', 'portion_score', 'temperature_score', 'overall_score', 'acceptance_percent', 'waste_percent', 'complaints', 'corrective_actions', 'photo_path', 'status', 'revision_notes', 'evaluator_id', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at'],
            ],
            'menu_allergen_substitution_ingredients' => [
                'soft_deletes' => false,
                'columns' => ['menu_allergen_substitution_id', 'ingredient_id', 'quantity_small_grams', 'quantity_large_grams', 'quantity_toddler_grams', 'quantity_maternal_grams', 'notes'],
            ],
            'menu_allergen_substitutions' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'menu_id', 'allergen_id', 'original_menu_item_id', 'replacement_name', 'menu_audience', 'affected_portions_override', 'affected_portion_profile_override', 'notes', 'is_active', 'created_by'],
            ],
            'menu_allergen_summaries' => [
                'soft_deletes' => false,
                'columns' => ['menu_id', 'allergen_id', 'source_ingredient_count', 'has_cross_contamination_risk', 'source_ingredients', 'calculated_at'],
            ],
            'menu_approvals' => [
                'soft_deletes' => false,
                'columns' => ['menu_id', 'user_id', 'action', 'previous_status', 'new_status', 'notes', 'snapshot'],
            ],
            'menu_beneficiary_category' => [
                'soft_deletes' => false,
                'columns' => ['menu_id', 'beneficiary_category_id', 'portion_multiplier'],
            ],            'menu_cycle_days' => [
                'soft_deletes' => false,
                'columns' => ['menu_cycle_id', 'day_number', 'service_date', 'menu_id', 'field_distribution_plan_id', 'notes', 'production_date', 'delivery_date', 'is_rapel', 'label_code', 'revision_status', 'revision_notes', 'revision_submitted_at', 'revision_approved_at', 'source_menu_id', 'snapshot_version', 'snapshot_created_at'],
            ],
            'menu_cycles' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'code', 'name', 'start_date', 'cycle_length_days', 'meal_type', 'status', 'notes', 'revision_notes', 'created_by', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'activated_at', 'end_date', 'nutrition_warning_count', 'revision_number', 'locked_at', 'beneficiary_period_id', 'buffer_percent', 'base_small_portions', 'base_large_portions', 'buffered_small_portions', 'buffered_large_portions', 'beneficiary_breakdown', 'beneficiary_snapshot_at'],
            ],
            'menu_day_revision_requests' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'menu_cycle_id', 'menu_cycle_day_id', 'original_menu_id', 'revision_menu_id', 'status', 'reason', 'impact_notes', 'decision_notes', 'snapshot', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'completed_by', 'completed_at'],
            ],
            'menu_items' => [
                'soft_deletes' => false,
                'columns' => ['menu_id', 'name', 'item_type', 'sort_order', 'preparation_notes', 'menu_audience', 'portion_size', 'portion_weight_small_grams', 'portion_weight_large_grams', 'portion_weight_toddler_grams', 'portion_weight_maternal_grams', 'portion_weight_grams'],
            ],
            'menu_nutrition_summaries' => [
                'soft_deletes' => false,
                'columns' => ['menu_id', 'beneficiary_category_id', 'nutrition_component_id', 'value_per_portion', 'standard_target', 'achievement_percent', 'calculated_at'],
            ],
            'menus' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'name', 'service_date', 'meal_type', 'status', 'created_by', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'notes', 'review_notes', 'revision_number', 'last_revision_submitted_at', 'last_revision_approved_at', 'source_menu_id', 'snapshot_cycle_day_id', 'is_cycle_snapshot', 'snapshot_version', 'snapshot_created_at', 'snapshot_payload', 'planned_portions'],
            ],
            'nutrition_components' => [
                'soft_deletes' => false,
                'columns' => ['code', 'name', 'unit', 'sort_order', 'is_active'],
            ],
            'nutrition_daily_report_components' => [
                'soft_deletes' => false,
                'columns' => ['nutrition_daily_report_id', 'nutrition_component_id', 'component_name_snapshot', 'unit_snapshot', 'planned_per_portion', 'actual_per_portion', 'target_per_portion', 'achievement_percent', 'planned_total', 'actual_total'],
            ],
            'nutrition_daily_reports' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'report_number', 'report_date', 'menu_id', 'planned_beneficiaries', 'actual_beneficiaries', 'planned_portions', 'served_portions', 'returned_portions', 'average_acceptance_percent', 'average_waste_percent', 'special_menu_count', 'allergen_conflicts_count', 'open_findings_count', 'status', 'summary', 'evaluation_notes', 'recommendations', 'revision_notes', 'generated_by', 'submitted_by', 'approved_by', 'generated_at', 'submitted_at', 'approved_at'],
            ],
            'nutrition_requirement_items' => [
                'soft_deletes' => false,
                'columns' => ['nutrition_requirement_plan_id', 'ingredient_id', 'ingredient_code_snapshot', 'ingredient_name_snapshot', 'unit_snapshot', 'quantity_per_portion_grams', 'base_quantity_grams', 'buffer_percent', 'total_quantity_grams', 'total_quantity_kg', 'edible_portion_percent', 'recipe_components', 'notes', 'effective_portions', 'calculation_breakdown'],
            ],
            'nutrition_requirement_plans' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'plan_number', 'requirement_date', 'menu_id', 'field_distribution_plan_id', 'total_portions', 'buffer_percent', 'total_items', 'total_weight_kg', 'status', 'notes', 'revision_notes', 'created_by', 'submitted_by', 'approved_by', 'generated_at', 'submitted_at', 'approved_at', 'effective_portions', 'portion_breakdown', 'requirement_type', 'original_requirement_plan_id', 'menu_day_revision_request_id', 'adjustment_generated_at', 'adjustment_notes'],
            ],
            'nutrition_standards' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'effective_from', 'effective_until', 'notes', 'is_active', 'beneficiary_category_id', 'nutrition_component_id', 'age_min_months', 'age_max_months', 'minimum_value', 'target_value', 'maximum_value'],
            ],
            'nutrition_workflow_histories' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'subject_type', 'subject_id', 'action', 'from_status', 'to_status', 'notes', 'snapshot', 'actor_id'],
            ],
            'portion_standards' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'beneficiary_category_id', 'meal_type', 'item_type', 'minimum_grams', 'target_grams', 'maximum_grams', 'effective_from', 'effective_until', 'notes', 'is_active'],
            ],
            'recipe_ingredients' => [
                'soft_deletes' => false,
                'columns' => ['menu_item_id', 'ingredient_id', 'notes', 'quantity_small_grams', 'quantity_large_grams', 'quantity_toddler_grams', 'quantity_maternal_grams', 'measurement_unit_id', 'quantity', 'quantity_grams', 'cooking_loss_percent'],
            ],
            'service_holidays' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'holiday_date', 'name', 'holiday_type', 'notes', 'is_active'],
            ],
        ];

        foreach ($tables as $tableName => $definition) {
            $this->createSmartTable(
                $tableName,
                $definition['columns'],
                $definition['soft_deletes']
            );
        }
    }

    public function down(): void
    {
        foreach (['service_holidays', 'recipe_ingredients', 'portion_standards', 'nutrition_workflow_histories', 'nutrition_standards', 'nutrition_requirement_plans', 'nutrition_requirement_items', 'nutrition_daily_reports', 'nutrition_daily_report_components', 'nutrition_components', 'menus', 'menu_nutrition_summaries', 'menu_items', 'menu_day_revision_requests', 'menu_cycles', 'menu_cycle_days', 'menu_beneficiary_category', 'menu_approvals', 'menu_allergen_summaries', 'menu_allergen_substitutions', 'menu_allergen_substitution_ingredients', 'menu_acceptance_evaluations', 'measurement_units', 'ingredients', 'ingredient_nutritions', 'ingredient_allergen', 'allergens'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};


