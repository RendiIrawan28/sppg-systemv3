<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FieldDestinationCatalog
{
    public function options(string $type, int $unitId): array
    {
        $modelClass = $this->modelClass($type);

        if (! $modelClass || ! class_exists($modelClass)) {
            return [];
        }

        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            return [];
        }

        $nameColumn = $this->firstExistingColumn($table, config('field_assistant.destination_columns.name', []));

        if (! $nameColumn) {
            return [];
        }

        $query = $modelClass::query();

        if (! $this->scopeToUnitAndActive($query, $table, $unitId)) {
            return [];
        }

        return $query
            ->orderBy($nameColumn)
            ->pluck($nameColumn, $model->getKeyName())
            ->all();
    }

    public function snapshot(string $type, int|string|null $id, int $unitId): array
    {
        if (blank($id)) {
            return $this->emptySnapshot();
        }

        $modelClass = $this->modelClass($type);

        if (! $modelClass || ! class_exists($modelClass)) {
            throw new RuntimeException("Model tujuan {$type} tidak ditemukan. Periksa config/field_assistant.php.");
        }

        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Tabel master tujuan {$table} tidak ditemukan.");
        }

        $query = $modelClass::query();

        if (! $this->scopeToUnitAndActive($query, $table, $unitId, activeOnly: false)) {
            throw new RuntimeException(
                "Master tujuan {$table} tidak memiliki kolom Unit SPPG yang dikenali. Periksa config/field_assistant.php."
            );
        }

        $record = $query->find($id);

        if (! $record) {
            throw new RuntimeException('Tujuan tidak ditemukan atau bukan milik Unit SPPG aktif.');
        }

        return [
            'destination_code_snapshot' => $this->value($record, 'code'),
            'destination_name_snapshot' => $this->value($record, 'name') ?: "Tujuan #{$id}",
            'address_snapshot' => $this->value($record, 'address'),
            'contact_name_snapshot' => $this->value($record, 'contact_name'),
            'contact_phone_snapshot' => $this->value($record, 'contact_phone'),
            'latitude_snapshot' => $this->value($record, 'latitude'),
            'longitude_snapshot' => $this->value($record, 'longitude'),
            'route_name' => $this->value($record, 'route') ?: 'Rute Utama',
            'registered_beneficiaries' => $this->beneficiaryCount($type, $record, $unitId),
        ];
    }

    private function modelClass(string $type): ?string
    {
        return config("field_assistant.destination_models.{$type}");
    }

    private function scopeToUnitAndActive(
        mixed $query,
        string $table,
        int $unitId,
        bool $activeOnly = true,
    ): bool {
        $unitColumn = $this->firstExistingColumn($table, config('field_assistant.destination_columns.unit', []));
        $activeColumn = $this->firstExistingColumn($table, config('field_assistant.destination_columns.active', []));

        if (! $unitColumn && config('field_assistant.require_unit_scope', true)) {
            return false;
        }

        if ($unitColumn) {
            $query->where($unitColumn, $unitId);
        }

        if ($activeOnly && $activeColumn) {
            $query->where($activeColumn, true);
        }

        return true;
    }

    private function value(Model $record, string $group): mixed
    {
        $columns = config("field_assistant.destination_columns.{$group}", []);
        $column = $this->firstExistingColumn($record->getTable(), $columns);

        return $column ? $record->getAttribute($column) : null;
    }

    private function beneficiaryCount(string $type, Model $destination, int $unitId): int
    {
        $directColumn = $this->firstExistingColumn(
            $destination->getTable(),
            config('field_assistant.destination_columns.beneficiary_count', [])
        );

        if ($directColumn && $destination->getAttribute($directColumn) !== null) {
            return max(0, (int) $destination->getAttribute($directColumn));
        }

        $beneficiaryClass = config('field_assistant.beneficiary_model');

        if (! $beneficiaryClass || ! class_exists($beneficiaryClass)) {
            return 0;
        }

        /** @var Model $beneficiary */
        $beneficiary = new $beneficiaryClass();
        $table = $beneficiary->getTable();

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $foreignGroup = $type === 'school' ? 'school_foreign_key' : 'posyandu_foreign_key';
        $foreignColumn = $this->firstExistingColumn(
            $table,
            config("field_assistant.beneficiary_columns.{$foreignGroup}", [])
        );

        if (! $foreignColumn) {
            return 0;
        }

        $query = DB::table($table)->where($foreignColumn, $destination->getKey());
        $unitColumn = $this->firstExistingColumn($table, config('field_assistant.beneficiary_columns.unit', []));
        $activeColumn = $this->firstExistingColumn($table, config('field_assistant.beneficiary_columns.active', []));

        if (! $unitColumn && config('field_assistant.require_unit_scope', true)) {
            return 0;
        }

        if ($unitColumn) {
            $query->where($unitColumn, $unitId);
        }

        if ($activeColumn) {
            $query->where($activeColumn, true);
        }

        return $query->count();
    }

    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function emptySnapshot(): array
    {
        return [
            'destination_code_snapshot' => null,
            'destination_name_snapshot' => null,
            'address_snapshot' => null,
            'contact_name_snapshot' => null,
            'contact_phone_snapshot' => null,
            'latitude_snapshot' => null,
            'longitude_snapshot' => null,
            'route_name' => null,
            'registered_beneficiaries' => 0,
        ];
    }
}
