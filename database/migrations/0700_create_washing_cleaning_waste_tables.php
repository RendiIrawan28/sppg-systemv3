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

        if (preg_match('/(quantity|weight|grams|kg|unit_price|total_price|amount|percent|temperature|ph|ppm|multiplier|rate|score|ratio|minimum|maximum|estimated|accepted|rejected|buffer)/', $column)) {
            $table->decimal($column, 14, 4)->nullable()->default(null);
            return;
        }

        if (preg_match('/(count|total|rows|year|minutes|duration|sequence|sort_order|attempts|containers|portions|beneficiaries|members|destinations|records|items|jobs|sample)/', $column)) {
            $table->unsignedInteger($column)->nullable()->default(0)->index($this->shortIndexName($tableName, $column));
            return;
        }

        if (preg_match('/(notes|description|address|summary|action|reason|remarks|instruction|evaluation|obstacles|recommendations|resolution|root_cause|caption|payload|exception|body|content|complaints)/', $column)) {
            $table->text($column)->nullable();
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
            'cleaning_areas' => [
                'soft_deletes' => true,
                'columns' => ['sppg_unit_id', 'code', 'name', 'category', 'location', 'frequency', 'standard_duration_minutes', 'instructions', 'default_checklist', 'is_active', 'created_by', 'updated_by'],
            ],
            'cleaning_checklist_items' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'category', 'item_name', 'is_mandatory', 'result', 'checked_at', 'checked_by', 'notes', 'sort_order'],
            ],
            'cleaning_chemical_usages' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'chemical_name', 'quantity', 'unit', 'purpose', 'dilution_ratio', 'batch_number', 'expiry_date', 'used_at', 'notes'],
            ],
            'cleaning_documentations' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'phase', 'photo_path', 'caption', 'captured_at', 'sort_order', 'created_by'],
            ],
            'cleaning_findings' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'found_at', 'category', 'severity', 'description', 'corrective_action', 'due_at', 'status', 'resolved_at', 'resolved_by', 'photo_path', 'notes'],
            ],
            'cleaning_histories' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'user_id', 'action', 'previous_state', 'new_state', 'previous_status', 'new_status', 'notes', 'snapshot'],
            ],
            'cleaning_sessions' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'cleaning_area_id', 'session_number', 'session_year', 'sequence_number', 'scheduled_date', 'shift', 'scheduled_start_at', 'started_at', 'completed_at', 'ready_at', 'duration_minutes', 'state', 'petugas_id', 'petugas_name_snapshot', 'supervisor_id', 'supervisor_name_snapshot', 'before_condition', 'after_condition', 'notes', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id'],
            ],
            'cleaning_waste_records' => [
                'soft_deletes' => false,
                'columns' => ['cleaning_session_id', 'waste_type', 'quantity', 'unit', 'disposal_method', 'handed_over_to', 'photo_path', 'notes'],
            ],
            'washing_checklist_items' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'category', 'item_name', 'is_mandatory', 'is_passed', 'checked_at', 'checked_by', 'notes', 'sort_order'],
            ],
            'washing_chemical_usages' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'chemical_name', 'quantity', 'unit', 'purpose', 'batch_number', 'expiry_date', 'used_at', 'notes'],
            ],
            'washing_deviations' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'occurred_at', 'category', 'severity', 'description', 'immediate_action', 'status', 'resolved_at', 'resolved_by', 'photo_path', 'notes'],
            ],
            'washing_documentations' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'phase', 'photo_path', 'caption', 'captured_at', 'sort_order', 'created_by'],
            ],
            'washing_histories' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'user_id', 'action', 'previous_state', 'new_state', 'previous_status', 'new_status', 'notes', 'snapshot'],
            ],
            'washing_measurements' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'phase', 'measured_at', 'water_temperature_celsius', 'minimum_temperature_celsius', 'maximum_temperature_celsius', 'water_ph', 'sanitizer_concentration_ppm', 'is_within_limit', 'corrective_action', 'measured_by', 'notes'],
            ],
            'washing_sessions' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'distribution_run_id', 'session_number', 'session_year', 'sequence_number', 'washing_date', 'menu_name_snapshot', 'expected_containers', 'received_containers', 'washed_containers', 'clean_containers', 'damaged_containers', 'rejected_containers', 'missing_containers', 'received_at', 'started_at', 'completed_at', 'ready_at', 'duration_minutes', 'state', 'washing_area', 'equipment_name', 'petugas_id', 'petugas_name_snapshot', 'notes', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id'],
            ],
            'washing_waste_records' => [
                'soft_deletes' => false,
                'columns' => ['washing_session_id', 'waste_type', 'quantity', 'unit', 'disposal_method', 'handed_over_to', 'photo_path', 'notes'],
            ],
            'waste_handover_histories' => [
                'soft_deletes' => false,
                'columns' => ['waste_handover_report_id', 'actor_id', 'action', 'from_status', 'to_status', 'notes', 'snapshot'],
            ],
            'waste_handover_items' => [
                'soft_deletes' => false,
                'columns' => ['waste_handover_report_id', 'waste_type', 'weight_kg', 'notes', 'photo_path', 'legacy_photo_url', 'sort_order', 'legacy_id'],
            ],
            'waste_handover_reports' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'division_type', 'report_number', 'document_year', 'sequence_number', 'report_date', 'first_party_name', 'first_party_position', 'first_party_address', 'second_party_name', 'second_party_position', 'second_party_address', 'notes', 'petugas_id', 'petugas_name_snapshot', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id'],
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
        foreach (['waste_handover_reports', 'waste_handover_items', 'waste_handover_histories', 'washing_waste_records', 'washing_sessions', 'washing_measurements', 'washing_histories', 'washing_documentations', 'washing_deviations', 'washing_chemical_usages', 'washing_checklist_items', 'cleaning_waste_records', 'cleaning_sessions', 'cleaning_histories', 'cleaning_findings', 'cleaning_documentations', 'cleaning_chemical_usages', 'cleaning_checklist_items', 'cleaning_areas'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};


