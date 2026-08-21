<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\TestDataCleanupLog;
use App\Models\User;
use App\Support\V3\TestDataCleanupRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class TestDataCleanupService
{
    /** @var array<string, array<int, string>> */
    private array $columns = [];

    /** @var array<int, string>|null */
    private ?array $tables = null;

    /**
     * Menghapus satu akar transaksi beserta data turunannya tanpa memeriksa status workflow.
     *
     * @return array<string, int>
     */
    public function purge(string $type, int $recordId, int $unitId, User $actor, string $reason): array
    {
        if (! $actor->is_super_admin) {
            abort(403);
        }

        $definition = app(TestDataCleanupRegistry::class)->get($type);
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass;
        $table = $model->getTable();
        $root = DB::table($table)->where('id', $recordId)->first();
        if (! $root || (property_exists($root, 'sppg_unit_id') && (int) $root->sppg_unit_id !== $unitId)) {
            abort(404);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Alasan pembersihan minimal 10 karakter.');
        }

        $counts = [];
        $visited = [];
        $filePaths = [];
        $affectedLotIds = [];
        $snapshot = (array) $root;
        $number = data_get($snapshot, $definition['number']);

        DB::transaction(function () use (
            $table, $recordId, $unitId, $type, $definition, $actor, $reason, $snapshot,
            $number, &$counts, &$visited, &$filePaths, &$affectedLotIds,
        ): void {
            $this->deleteNode(
                $table,
                $recordId,
                $counts,
                $visited,
                $filePaths,
                $affectedLotIds,
            );
            $this->recalculateLots($affectedLotIds);

            TestDataCleanupLog::query()->create([
                'sppg_unit_id' => $unitId,
                'actor_id' => $actor->getKey(),
                'actor_name_snapshot' => $actor->name,
                'record_type' => $type,
                'record_label' => $definition['label'],
                'source_table' => $table,
                'source_id' => $recordId,
                'source_number' => filled($number) ? (string) $number : null,
                'reason' => $reason,
                'record_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'deleted_counts' => $counts,
                'deleted_at' => now(),
            ]);
        });

        if ($filePaths !== []) {
            Storage::disk('public')->delete(array_values(array_unique($filePaths)));
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, true>  $visited
     * @param  array<int, string>  $filePaths
     * @param  array<int, int>  $affectedLotIds
     */
    private function deleteNode(
        string $table,
        int $id,
        array &$counts,
        array &$visited,
        array &$filePaths,
        array &$affectedLotIds,
    ): void {
        $key = $table.':'.$id;
        if (isset($visited[$key]) || ! $this->hasColumn($table, 'id')) {
            return;
        }
        $visited[$key] = true;

        $row = DB::table($table)->where('id', $id)->first();
        if (! $row) {
            return;
        }
        $values = (array) $row;
        $this->collectFilePaths($values, $filePaths);

        if ($table === 'stock_movements' && filled($values['inventory_lot_id'] ?? null)) {
            $affectedLotIds[] = (int) $values['inventory_lot_id'];
        }

        $this->deleteOwnedPointers($table, $values, $counts, $visited, $filePaths, $affectedLotIds);
        $this->deleteConnectedOperationalRoots($table, $id, $values, $counts, $visited, $filePaths, $affectedLotIds);

        $foreignColumn = Str::singular($table).'_id';
        foreach ($this->tableNames() as $childTable) {
            if ($childTable === $table || ! $this->hasColumn($childTable, $foreignColumn)) {
                continue;
            }
            foreach (DB::table($childTable)->where($foreignColumn, $id)->pluck('id') as $childId) {
                $this->deleteNode($childTable, (int) $childId, $counts, $visited, $filePaths, $affectedLotIds);
            }
        }

        $typeValues = array_values(array_unique([
            $table,
            Str::singular($table),
            $this->modelClassForTable($table),
        ]));
        foreach ($this->tableNames() as $childTable) {
            foreach ([['source_type', 'source_id'], ['reference_type', 'reference_id']] as [$typeColumn, $idColumn]) {
                if (! $this->hasColumn($childTable, $typeColumn) || ! $this->hasColumn($childTable, $idColumn)) {
                    continue;
                }
                foreach (DB::table($childTable)
                    ->whereIn($typeColumn, $typeValues)
                    ->where($idColumn, $id)
                    ->pluck('id') as $childId) {
                    $this->deleteNode($childTable, (int) $childId, $counts, $visited, $filePaths, $affectedLotIds);
                }
            }
        }

        $deleted = DB::table($table)->where('id', $id)->delete();
        if ($deleted > 0) {
            $counts[$table] = ($counts[$table] ?? 0) + $deleted;
        }
    }

    /** @param array<string, mixed> $values */
    private function deleteOwnedPointers(
        string $table,
        array $values,
        array &$counts,
        array &$visited,
        array &$filePaths,
        array &$affectedLotIds,
    ): void {
        $owned = [
            'waste_handover_report_id' => 'waste_handover_reports',
        ];
        if ($table === 'opening_stock_items') {
            $owned['inventory_lot_id'] = 'inventory_lots';
        }

        foreach ($owned as $column => $ownedTable) {
            if (filled($values[$column] ?? null)) {
                $this->deleteNode(
                    $ownedTable,
                    (int) $values[$column],
                    $counts,
                    $visited,
                    $filePaths,
                    $affectedLotIds,
                );
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function deleteConnectedOperationalRoots(
        string $table,
        int $id,
        array $values,
        array &$counts,
        array &$visited,
        array &$filePaths,
        array &$affectedLotIds,
    ): void {
        if ($table === 'inventory_lots') {
            $withdrawalIds = DB::table('warehouse_withdrawal_items')
                ->where('inventory_lot_id', $id)
                ->pluck('warehouse_withdrawal_id')
                ->filter()
                ->unique();
            foreach ($withdrawalIds as $withdrawalId) {
                $this->deleteNode('warehouse_withdrawals', (int) $withdrawalId, $counts, $visited, $filePaths, $affectedLotIds);
            }
        }

        if ($table === 'preparation_outputs') {
            $links = DB::table('preparation_output_withdrawals')
                ->where('preparation_output_id', $id)
                ->get(['processing_batch_id', 'portioning_session_id']);
            foreach ($links as $link) {
                if ($link->processing_batch_id) {
                    $this->deleteNode('processing_batches', (int) $link->processing_batch_id, $counts, $visited, $filePaths, $affectedLotIds);
                }
                if ($link->portioning_session_id) {
                    $this->deleteNode('portioning_sessions', (int) $link->portioning_session_id, $counts, $visited, $filePaths, $affectedLotIds);
                }
            }
        }
    }

    /** @param array<int, int> $affectedLotIds */
    private function recalculateLots(array $affectedLotIds): void
    {
        foreach (array_unique($affectedLotIds) as $lotId) {
            $lot = InventoryLot::query()->find($lotId);
            if (! $lot) {
                continue;
            }
            $totals = DB::table('stock_movements')
                ->where('inventory_lot_id', $lotId)
                ->selectRaw('COALESCE(SUM(COALESCE(quantity_in, quantity_in_kg, 0)), 0) as incoming')
                ->selectRaw('COALESCE(SUM(COALESCE(quantity_out, quantity_out_kg, 0)), 0) as outgoing')
                ->first();
            $balance = max(0, (float) $totals->incoming - (float) $totals->outgoing);
            $lot->forceFill([
                'balance_quantity' => $balance,
                'balance_quantity_kg' => $balance,
                'status' => $balance > 0 ? InventoryLot::AVAILABLE : InventoryLot::DEPLETED,
            ])->saveQuietly();
        }
    }

    /** @param array<string, mixed> $values @param array<int, string> $paths */
    private function collectFilePaths(array $values, array &$paths): void
    {
        foreach ($values as $column => $value) {
            if (blank($value)) {
                continue;
            }
            if (str_ends_with($column, '_path') && is_string($value)) {
                $paths[] = $value;
            }
            if (str_ends_with($column, '_paths') && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $path) {
                        if (is_string($path) && $path !== '') {
                            $paths[] = $path;
                        }
                    }
                }
            }
        }
    }

    /** @return array<int, string> */
    private function tableNames(): array
    {
        return $this->tables ??= collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table): bool => $table !== 'test_data_cleanup_logs' && $this->hasColumn($table, 'id'))
            ->values()
            ->all();
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! isset($this->columns[$table])) {
            $this->columns[$table] = collect(Schema::getColumns($table))->pluck('name')->all();
        }

        return in_array($column, $this->columns[$table], true);
    }

    private function modelClassForTable(string $table): string
    {
        return 'App\\Models\\'.Str::studly(Str::singular($table));
    }
}
