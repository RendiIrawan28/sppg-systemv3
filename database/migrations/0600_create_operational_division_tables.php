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
            'distribution_documentations' => [
                'soft_deletes' => false,
                'columns' => ['distribution_run_id', 'phase', 'photo_path', 'caption', 'captured_at', 'sort_order', 'created_by'],
            ],
            'distribution_histories' => [
                'soft_deletes' => false,
                'columns' => ['distribution_run_id', 'user_id', 'action', 'previous_state', 'new_state', 'previous_status', 'new_status', 'notes', 'snapshot'],
            ],
            'distribution_incidents' => [
                'soft_deletes' => false,
                'columns' => ['distribution_run_id', 'distribution_stop_id', 'occurred_at', 'category', 'severity', 'description', 'immediate_action', 'status', 'resolved_at', 'resolved_by', 'photo_path', 'notes'],
            ],
            'distribution_runs' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'portioning_session_id', 'run_number', 'run_year', 'sequence_number', 'distribution_date', 'menu_name_snapshot', 'planned_small_portions', 'planned_large_portions', 'loaded_small_portions', 'loaded_large_portions', 'delivered_small_portions', 'delivered_large_portions', 'returned_small_portions', 'returned_large_portions', 'planned_departure_at', 'actual_departure_at', 'returned_at', 'duration_minutes', 'state', 'vehicle_name', 'vehicle_plate', 'driver_name', 'departure_temperature_celsius', 'petugas_id', 'petugas_name_snapshot', 'notes', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id', 'field_distribution_plan_id'],
            ],
            'distribution_stops' => [
                'soft_deletes' => false,
                'columns' => ['distribution_run_id', 'route_name', 'destination_name', 'destination_type', 'address', 'contact_name', 'contact_phone', 'sequence_order', 'planned_arrival_at', 'arrived_at', 'small_portions', 'large_portions', 'delivered_small_portions', 'delivered_large_portions', 'returned_small_portions', 'returned_large_portions', 'containers_sent', 'containers_returned', 'containers_damaged', 'containers_lost', 'arrival_temperature_celsius', 'status', 'recipient_name', 'recipient_position', 'signature_path', 'handover_photo_path', 'latitude', 'longitude', 'delay_minutes', 'failure_reason', 'notes', 'field_distribution_plan_destination_id'],
            ],
            'portioning_deviations' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'detected_at', 'category', 'severity', 'description', 'corrective_action', 'status', 'resolved_at', 'resolved_by', 'notes'],
            ],
            'portioning_documentations' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'phase', 'photo_path', 'caption', 'captured_at', 'sort_order', 'created_by'],
            ],
            'portioning_handovers' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'handed_over_at', 'small_portions', 'large_portions', 'received_by_user_id', 'received_by_name', 'photo_path', 'notes', 'created_by'],
            ],
            'portioning_histories' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'user_id', 'action', 'previous_state', 'new_state', 'previous_status', 'new_status', 'notes', 'snapshot'],
            ],
            'portioning_leftover_records' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'route_name', 'checked_at', 'food_type', 'weight_kg', 'reason', 'notes', 'photo_path', 'created_by'],
            ],
            'portioning_route_allocations' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'route_name', 'destination_type', 'target_small_portions', 'target_large_portions', 'actual_small_portions', 'actual_large_portions', 'portioned_at', 'sort_order', 'notes', 'field_distribution_plan_destination_id', 'destination_name', 'address', 'contact_name', 'contact_phone', 'planned_arrival_at', 'planned_departure_at', 'latitude', 'longitude'],
            ],
            'portioning_sessions' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'processing_batch_id', 'session_number', 'session_year', 'sequence_number', 'portioning_date', 'menu_name_snapshot', 'target_small_portions', 'target_large_portions', 'actual_small_portions', 'actual_large_portions', 'started_at', 'completed_at', 'duration_minutes', 'state', 'petugas_id', 'petugas_name_snapshot', 'notes', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id', 'field_distribution_plan_id'],
            ],
            'portioning_weight_samples' => [
                'soft_deletes' => false,
                'columns' => ['portioning_session_id', 'portion_size', 'component_name', 'sample_number', 'target_weight_grams', 'actual_weight_grams', 'tolerance_grams', 'deviation_grams', 'is_within_tolerance', 'corrective_action', 'checked_at', 'checked_by', 'checked_name_snapshot', 'notes'],
            ],
            'preparation_material_inspection_histories' => [
                'soft_deletes' => false,
                'columns' => ['preparation_material_inspection_id', 'actor_id', 'action', 'from_status', 'to_status', 'notes', 'snapshot'],
            ],
            'preparation_material_inspections' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'report_date', 'ingredient_id', 'material_name', 'quantity', 'measurement_unit_id', 'unit_name', 'condition', 'remarks', 'petugas_id', 'petugas_name_snapshot', 'photo_path', 'legacy_photo_url', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id'],
            ],
            'processing_batch_destinations' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'field_distribution_plan_destination_id', 'destination_type', 'destination_id', 'destination_name_snapshot', 'route_name', 'sequence_order', 'small_portions', 'large_portions', 'total_portions', 'planned_departure_at', 'planned_arrival_at', 'notes'],
            ],
            'processing_batches' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'batch_number', 'batch_year', 'sequence_number', 'production_date', 'menu_id', 'menu_name_snapshot', 'product_name', 'target_output_quantity', 'target_output_unit', 'actual_output_quantity', 'actual_output_unit', 'started_at', 'completed_at', 'duration_minutes', 'state', 'petugas_id', 'petugas_name_snapshot', 'notes', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name', 'legacy_created_at', 'import_batch_id', 'preparation_material_handover_id', 'menu_cycle_day_id', 'service_date', 'is_rapel', 'batch_type', 'field_distribution_plan_id'],
            ],
            'processing_deviations' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'category', 'severity', 'description', 'corrective_action', 'status', 'detected_at', 'resolved_at', 'resolved_by', 'notes'],
            ],
            'processing_documentations' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'documentation_type', 'caption', 'photo_path', 'captured_at', 'created_by', 'sort_order'],
            ],
            'processing_handovers' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'handed_over_at', 'output_quantity', 'unit_name', 'received_by_user_id', 'received_by_name', 'notes', 'photo_path', 'created_by'],
            ],
            'processing_histories' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'actor_id', 'action', 'from_state', 'to_state', 'from_status', 'to_status', 'notes', 'snapshot'],
            ],
            'processing_material_usages' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'ingredient_id', 'material_name', 'quantity', 'measurement_unit_id', 'unit_name', 'notes', 'sort_order'],
            ],
            'processing_steps' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'step_name', 'started_at', 'completed_at', 'duration_minutes', 'temperature_celsius', 'notes', 'photo_path', 'sort_order'],
            ],
            'processing_temperature_logs' => [
                'soft_deletes' => false,
                'columns' => ['processing_batch_id', 'checked_at', 'checkpoint', 'product_name', 'temperature_celsius', 'minimum_temperature', 'maximum_temperature', 'is_within_limit', 'corrective_action', 'measured_by', 'measured_name_snapshot', 'photo_path', 'notes', 'sort_order'],
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
        foreach (['processing_temperature_logs', 'processing_steps', 'processing_material_usages', 'processing_histories', 'processing_handovers', 'processing_documentations', 'processing_deviations', 'processing_batches', 'processing_batch_destinations', 'preparation_material_inspections', 'preparation_material_inspection_histories', 'portioning_weight_samples', 'portioning_sessions', 'portioning_route_allocations', 'portioning_leftover_records', 'portioning_histories', 'portioning_handovers', 'portioning_documentations', 'portioning_deviations', 'distribution_stops', 'distribution_runs', 'distribution_incidents', 'distribution_histories', 'distribution_documentations'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
