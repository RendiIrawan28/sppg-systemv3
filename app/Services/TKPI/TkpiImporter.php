<?php

namespace App\Services\TKPI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TkpiImporter
{
    private array $columnCache = [];

    /**
     * @return array<string,int>
     */
    public function import(
        int $unitId,
        string $mode = 'all',
        bool $updateExisting = true,
        bool $dryRun = false,
        ?float $missingBddFallback = 100.0,
        ?callable $progress = null,
    ): array {
        $this->assertSchema();

        if (! in_array($mode, ['all', 'raw', 'processed'], true)) {
            throw new RuntimeException('Mode TKPI harus all, raw, atau processed.');
        }

        $path = (string) config('tkpi.csv_path', database_path('data/tkpi_2017.csv'));

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Dataset TKPI tidak dapat dibaca: {$path}");
        }

        $stats = [
            'rows_read' => 0,
            'foods_created' => 0,
            'foods_updated' => 0,
            'foods_skipped' => 0,
            'nutrition_written' => 0,
            'missing_bdd_fallback' => 0,
        ];

        DB::beginTransaction();

        try {
            $componentIds = $this->synchronizeComponents($unitId);
            $gramUnitId = $this->resolveGramUnitId();
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Gagal membuka dataset TKPI: {$path}");
            }

            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException('Header CSV TKPI tidak valid.');
            }
            $headers = array_map(fn ($value) => ltrim((string) $value, "\xEF\xBB\xBF"), $headers);

            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('Jumlah kolom CSV TKPI tidak konsisten pada baris '.($stats['rows_read'] + 2).'.');
                }

                $row = array_combine($headers, $values);
                if (! is_array($row)) {
                    throw new RuntimeException('Baris CSV TKPI tidak dapat dipetakan.');
                }

                $stats['rows_read']++;

                if ($mode !== 'all' && ($row['food_type'] ?? null) !== $mode) {
                    $stats['foods_skipped']++;
                    continue;
                }

                $bdd = $this->numberOrNull($row['edible_portion_percent'] ?? null);
                $bddWasFallback = false;
                if ($bdd === null) {
                    if ($missingBddFallback === null) {
                        $stats['foods_skipped']++;
                        continue;
                    }
                    $bdd = $missingBddFallback;
                    $bddWasFallback = true;
                    $stats['missing_bdd_fallback']++;
                }

                $ingredient = $this->upsertIngredient(
                    unitId: $unitId,
                    row: $row,
                    bdd: $bdd,
                    bddWasFallback: $bddWasFallback,
                    gramUnitId: $gramUnitId,
                    updateExisting: $updateExisting,
                );

                if ($ingredient['action'] === 'created') {
                    $stats['foods_created']++;
                } elseif ($ingredient['action'] === 'updated') {
                    $stats['foods_updated']++;
                } else {
                    $stats['foods_skipped']++;
                }

                if ($ingredient['action'] !== 'skipped') {
                    $stats['nutrition_written'] += $this->upsertNutritions(
                        ingredientId: $ingredient['id'],
                        row: $row,
                        componentIds: $componentIds,
                    );
                }

                if ($progress && $stats['rows_read'] % 100 === 0) {
                    $progress($stats);
                }
            }

            fclose($handle);

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $stats;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    private function assertSchema(): void
    {
        foreach (['ingredients', 'nutrition_components', 'ingredient_nutritions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Tabel {$table} belum tersedia.");
            }
        }

        foreach (['ingredient_id', 'nutrition_component_id', 'value_per_100g'] as $column) {
            if (! Schema::hasColumn('ingredient_nutritions', $column)) {
                throw new RuntimeException("Kolom ingredient_nutritions.{$column} belum tersedia.");
            }
        }
    }

    /** @return array<string,int> */
    private function synchronizeComponents(int $unitId): array
    {
        $columns = $this->columns('nutrition_components');
        $hasCode = in_array('code', $columns, true);
        $hasUnitScope = in_array('sppg_unit_id', $columns, true);
        $ids = [];
        $sort = 100;

        foreach (TkpiCatalog::components() as $canonicalCode => $definition) {
            $query = DB::table('nutrition_components');
            if ($hasUnitScope) {
                $query->where('sppg_unit_id', $unitId);
            }

            $aliases = array_map('strtoupper', array_unique([
                $canonicalCode,
                ...$definition['aliases'],
            ]));

            $component = null;
            if ($hasCode) {
                $component = (clone $query)
                    ->whereIn(DB::raw('UPPER(code)'), $aliases)
                    ->first();
            }

            if (! $component && in_array('name', $columns, true)) {
                $component = (clone $query)
                    ->whereRaw('LOWER(name) = ?', [Str::lower($definition['name'])])
                    ->first();
            }

            if (! $component) {
                $payload = $this->filterColumns('nutrition_components', [
                    'sppg_unit_id' => $unitId,
                    'code' => $canonicalCode,
                    'name' => $definition['name'],
                    'unit' => $definition['unit'],
                    'symbol' => $definition['unit'],
                    'description' => 'Komponen gizi TKPI 2017 per 100 gram BDD.',
                    'sort_order' => (int) ($definition['sort_order'] ?? $sort),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $id = (int) DB::table('nutrition_components')->insertGetId($payload);
            } else {
                $id = (int) $component->id;
                // Jangan menimpa sort_order komponen MBG yang sudah menjadi acuan validasi.
                // Importer hanya menyeragamkan kode/nama/unit agar TKPI tidak membuat komponen duplikat.
                $payload = $this->filterColumns('nutrition_components', [
                    'code' => $canonicalCode,
                    'name' => $definition['name'],
                    'unit' => $definition['unit'],
                    'symbol' => $definition['unit'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                if ($payload !== []) {
                    DB::table('nutrition_components')->where('id', $id)->update($payload);
                }
            }

            $ids[$canonicalCode] = $id;
            $sort++;
        }

        return $ids;
    }

    private function resolveGramUnitId(): ?int
    {
        if (! Schema::hasColumn('ingredients', 'measurement_unit_id')) {
            return null;
        }
        if (! Schema::hasTable('measurement_units')) {
            throw new RuntimeException('Tabel measurement_units diperlukan karena ingredients.measurement_unit_id tersedia.');
        }

        $columns = $this->columns('measurement_units');
        $query = DB::table('measurement_units');
        $unit = null;

        if (in_array('symbol', $columns, true)) {
            $unit = (clone $query)->whereRaw('LOWER(symbol) = ?', ['g'])->first();
        }
        if (! $unit && in_array('code', $columns, true)) {
            $unit = (clone $query)->whereIn(DB::raw('UPPER(code)'), ['G', 'GR', 'GRAM'])->first();
        }
        if (! $unit && in_array('name', $columns, true)) {
            $unit = (clone $query)->whereIn(DB::raw('LOWER(name)'), ['gram', 'grams'])->first();
        }

        if ($unit) {
            return (int) $unit->id;
        }

        $payload = $this->filterColumns('measurement_units', [
            'code' => 'G', 'name' => 'Gram', 'symbol' => 'g',
            'conversion_to_gram' => 1, 'gram_conversion_factor' => 1,
            'unit_type' => 'weight', 'to_base_factor' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('measurement_units')->insertGetId($payload);
    }

    /** @return array{id:int,action:string} */
    private function upsertIngredient(
        int $unitId,
        array $row,
        float $bdd,
        bool $bddWasFallback,
        ?int $gramUnitId,
        bool $updateExisting,
    ): array {
        $columns = $this->columns('ingredients');
        $code = (string) config('tkpi.ingredient_code_prefix', '').($row['tkpi_code'] ?? '');
        $query = DB::table('ingredients');

        if (in_array('sppg_unit_id', $columns, true)) {
            $query->where('sppg_unit_id', $unitId);
        }

        if (in_array('code', $columns, true)) {
            $query->where('code', $code);
        } elseif (in_array('tkpi_code', $columns, true)) {
            $query->where('tkpi_code', $row['tkpi_code']);
        } else {
            $query->where('name', $row['name']);
        }

        $existing = $query->first();
        $description = sprintf(
            'Sumber: TKPI 2017; kode %s; kelompok %s; jenis %s; rujukan %s.%s',
            $row['tkpi_code'],
            $row['group_name'],
            $row['food_type'] === 'raw' ? 'mentah/segar' : 'olahan',
            $row['source_reference'] ?: '-',
            $bddWasFallback
                ? ' BDD sumber kosong; sistem memakai fallback '.rtrim(rtrim(number_format($bdd, 2, '.', ''), '0'), '.').'%%.'
                : ''
        );

        $payload = $this->filterColumns('ingredients', [
            'sppg_unit_id' => $unitId,
            'measurement_unit_id' => $gramUnitId,
            'code' => $code,
            'tkpi_code' => $row['tkpi_code'],
            'name' => $row['name'],
            'category' => $row['group_name'],
            'food_group' => $row['group_name'],
            'food_type' => $row['food_type'],
            'source' => 'TKPI 2017',
            'nutrition_source' => 'TKPI 2017',
            'source_reference' => $row['source_reference'],
            'description' => $description,
            'notes' => $description,
            'edible_portion_percent' => $bdd,
            'bdd_is_estimated' => $bddWasFallback,
            'bdd_source' => $bddWasFallback ? 'fallback_operasional' : 'TKPI 2017',
            'is_active' => true,
            'updated_at' => now(),
        ]);

        if (! $existing) {
            if (in_array('created_at', $columns, true)) {
                $payload['created_at'] = now();
            }
            return [
                'id' => (int) DB::table('ingredients')->insertGetId($payload),
                'action' => 'created',
            ];
        }

        if (! $updateExisting) {
            return ['id' => (int) $existing->id, 'action' => 'skipped'];
        }

        DB::table('ingredients')->where('id', $existing->id)->update($payload);
        return ['id' => (int) $existing->id, 'action' => 'updated'];
    }

    private function upsertNutritions(int $ingredientId, array $row, array $componentIds): int
    {
        $count = 0;
        $columns = $this->columns('ingredient_nutritions');

        foreach (TkpiCatalog::csvComponentMap() as $csvColumn => $componentCode) {
            $value = $this->numberOrNull($row[$csvColumn] ?? null);

            // Blank pada TKPI berarti belum dianalisis/tidak tersedia, bukan nol.
            if ($value === null) {
                continue;
            }

            $key = [
                'ingredient_id' => $ingredientId,
                'nutrition_component_id' => $componentIds[$componentCode],
            ];
            $payload = $this->filterColumns('ingredient_nutritions', [
                ...$key,
                'value_per_100g' => $value,
                'source' => 'TKPI 2017',
                'notes' => 'Nilai per 100 gram BDD.',
                'updated_at' => now(),
            ]);

            $existing = DB::table('ingredient_nutritions')->where($key)->first();
            if ($existing) {
                DB::table('ingredient_nutritions')->where('id', $existing->id)->update($payload);
            } else {
                if (in_array('created_at', $columns, true)) {
                    $payload['created_at'] = now();
                }
                DB::table('ingredient_nutritions')->insert($payload);
            }
            $count++;
        }

        return $count;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new RuntimeException("Nilai numerik TKPI tidak valid: {$value}");
        }
        return (float) $value;
    }

    private function columns(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::getColumnListing($table);
    }

    private function filterColumns(string $table, array $payload): array
    {
        $allowed = array_flip($this->columns($table));
        return array_filter(
            $payload,
            fn ($value, $column) => array_key_exists($column, $allowed) && $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
