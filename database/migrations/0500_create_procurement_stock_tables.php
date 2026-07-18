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
            'preparation_material_handover_items' => [
                'soft_deletes' => false,
                'columns' => ['preparation_material_handover_id', 'ingredient_id', 'ingredient_name_snapshot', 'unit_snapshot', 'requested_quantity_kg', 'handed_over_quantity_kg', 'supplier_batch_number', 'expired_date', 'notes'],
            ],
            'preparation_material_handovers' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'handover_number', 'handover_date', 'status', 'warehouse_officer_name', 'preparation_officer_name', 'notes', 'created_by', 'handed_over_by', 'received_by', 'handed_over_at', 'received_at'],
            ],
            'procurement_request_items' => [
                'soft_deletes' => false,
                'columns' => ['procurement_request_id', 'nutrition_requirement_item_id', 'ingredient_id', 'supplier_id', 'ingredient_code_snapshot', 'ingredient_name_snapshot', 'unit_snapshot', 'requested_quantity', 'approved_quantity', 'requested_quantity_kg', 'approved_quantity_kg', 'estimated_unit_price', 'estimated_total_price', 'notes'],
            ],
            'procurement_requests' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'request_number', 'request_date', 'needed_date', 'nutrition_requirement_plan_id', 'field_distribution_plan_id', 'status', 'total_items', 'estimated_total_amount', 'notes', 'finance_notes', 'created_by', 'submitted_by', 'approved_by', 'ordered_by', 'submitted_at', 'approved_at', 'ordered_at', 'price_status', 'price_finalized_by', 'price_finalized_at'],
            ],
            'stock_movements' => [
                'soft_deletes' => false,
                'columns' => ['uuid', 'sppg_unit_id', 'ingredient_id', 'ingredient_name_snapshot', 'unit_snapshot', 'movement_type', 'movement_date', 'quantity_in_kg', 'quantity_out_kg', 'source_type', 'source_id', 'reference_number', 'supplier_batch_number', 'expired_date', 'notes', 'created_by'],
            ],
            'stock_receipt_items' => [
                'soft_deletes' => false,
                'columns' => ['stock_receipt_id', 'procurement_request_item_id', 'ingredient_id', 'supplier_id', 'ingredient_name_snapshot', 'unit_snapshot', 'ordered_quantity_kg', 'received_quantity_kg', 'accepted_quantity_kg', 'rejected_quantity_kg', 'supplier_batch_number', 'expired_date', 'received_temperature_celsius', 'quality_status', 'quality_notes'],
            ],
            'stock_receipts' => [
                'soft_deletes' => true,
                'columns' => ['uuid', 'sppg_unit_id', 'procurement_request_id', 'receipt_number', 'receipt_date', 'status', 'received_by_name', 'notes', 'created_by', 'received_by', 'received_at'],
            ],
            'suppliers' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'name', 'contact_person', 'phone', 'address', 'notes', 'is_active'],
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
        foreach (['suppliers', 'stock_receipts', 'stock_receipt_items', 'stock_movements', 'procurement_requests', 'procurement_request_items', 'preparation_material_handovers', 'preparation_material_handover_items'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
