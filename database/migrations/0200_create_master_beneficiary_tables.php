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
            'beneficiaries' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'beneficiaryable_type', 'beneficiaryable_id', 'beneficiary_category_id', 'code', 'external_id', 'name', 'group_name', 'birth_date', 'gender', 'start_date', 'end_date', 'allergy_notes', 'special_needs', 'notes', 'data_source', 'last_import_id', 'is_active', 'parent_name', 'recipient_position', 'address'],
            ],
            'beneficiary_allergen' => [
                'soft_deletes' => false,
                'columns' => ['beneficiary_id', 'allergen_id', 'severity', 'reaction_notes', 'verified_by', 'verified_at', 'is_active'],
            ],
            'beneficiary_categories' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'group_type', 'education_level', 'grade_start', 'grade_end', 'portion_size', 'menu_audience'],
            ],
            'beneficiary_imports' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'institution_type', 'institution_id', 'uploaded_by', 'original_filename', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'imported_rows', 'updated_rows', 'errors', 'started_at', 'completed_at'],
            ],
            'beneficiary_period_destinations' => [
                'soft_deletes' => false,
                'columns' => ['beneficiary_period_id', 'destination_key', 'destination_type', 'destination_id', 'destination_code_snapshot', 'destination_name_snapshot', 'address_snapshot', 'contact_name_snapshot', 'contact_phone_snapshot', 'latitude_snapshot', 'longitude_snapshot', 'preferred_delivery_time', 'sort_order', 'is_active', 'notes'],
            ],
            'beneficiary_period_histories' => [
                'soft_deletes' => false,
                'columns' => ['beneficiary_period_id', 'user_id', 'action', 'from_status', 'to_status', 'notes', 'metadata'],
            ],
            'beneficiary_period_items' => [
                'soft_deletes' => false,
                'columns' => ['beneficiary_period_id', 'destination_type', 'destination_id', 'destination_name_snapshot', 'destination_code_snapshot', 'address_snapshot', 'contact_name_snapshot', 'contact_phone_snapshot', 'beneficiary_category_id', 'beneficiary_category_code_snapshot', 'beneficiary_category_name_snapshot', 'portion_category', 'menu_audience', 'master_count', 'notes'],
            ],
            'beneficiary_period_members' => [
                'soft_deletes' => false,
                'columns' => ['beneficiary_period_id', 'beneficiary_period_destination_id', 'source_beneficiary_id', 'beneficiary_category_id', 'member_code', 'identity_number', 'name', 'birth_date', 'gender', 'parent_name', 'recipient_position', 'education_level', 'class_group', 'beneficiary_category_code_snapshot', 'beneficiary_category_name_snapshot', 'portion_category', 'menu_audience', 'address', 'allergy_notes', 'special_needs', 'notes', 'is_active'],
            ],
            'beneficiary_periods' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'name', 'start_date', 'end_date', 'status', 'notes', 'created_by', 'approved_by', 'approved_at', 'source_period_id', 'submitted_by', 'submitted_at', 'locked_at', 'closed_at', 'document_number', 'revision_number', 'destination_count', 'total_members', 'active_members'],
            ],
            'posyandus' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'name', 'address', 'village', 'district', 'city', 'province', 'service_area', 'pic_name', 'pic_phone', 'pic_email', 'latitude', 'longitude', 'receiving_time', 'is_active', 'notes'],
            ],
            'schools' => [
                'soft_deletes' => false,
                'columns' => ['sppg_unit_id', 'code', 'npsn', 'name', 'education_level', 'address', 'village', 'district', 'city', 'province', 'pic_name', 'pic_phone', 'pic_email', 'latitude', 'longitude', 'receiving_time', 'is_active', 'notes'],
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
        foreach (['schools', 'posyandus', 'beneficiary_periods', 'beneficiary_period_members', 'beneficiary_period_items', 'beneficiary_period_histories', 'beneficiary_period_destinations', 'beneficiary_imports', 'beneficiary_categories', 'beneficiary_allergen', 'beneficiaries'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};


