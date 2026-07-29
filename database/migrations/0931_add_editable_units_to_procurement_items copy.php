<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        $addMeasurementUnit = ! Schema::hasColumn('procurement_request_items', 'measurement_unit_id');
        $addKgPerUnit = ! Schema::hasColumn('procurement_request_items', 'kg_per_unit_snapshot');

        if ($addMeasurementUnit || $addKgPerUnit) {
            Schema::table('procurement_request_items', function (Blueprint $table) use ($addMeasurementUnit, $addKgPerUnit): void {
                if ($addMeasurementUnit) {
                    $table->foreignId('measurement_unit_id')
                        ->nullable()
                        ->after('unit_snapshot')
                        ->constrained('measurement_units')
                        ->nullOnDelete();
                }

                if ($addKgPerUnit) {
                    $table->decimal('kg_per_unit_snapshot', 14, 6)
                        ->nullable()
                        ->after('measurement_unit_id');
                }
            });
        }

        $units = DB::table('measurement_units')
            ->select(['id', 'code', 'symbol', 'unit_type', 'to_base_factor'])
            ->get();

        $unitsById = $units->keyBy('id');
        $unitsByCode = $units->keyBy(fn (object $unit): string => strtolower(trim((string) $unit->code)));
        $unitsBySymbol = $units->keyBy(fn (object $unit): string => strtolower(trim((string) $unit->symbol)));

        DB::table('procurement_request_items')
            ->select([
                'id',
                'ingredient_id',
                'unit_snapshot',
                'requested_quantity',
                'requested_quantity_kg',
                'measurement_unit_id',
                'kg_per_unit_snapshot',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($unitsById, $unitsByCode, $unitsBySymbol): void {
                foreach ($items as $item) {
                    $ingredient = $item->ingredient_id
                        ? DB::table('ingredients')
                            ->select(['measurement_unit_id', 'grams_per_unit'])
                            ->where('id', $item->ingredient_id)
                            ->first()
                        : null;

                    $snapshot = strtolower(trim((string) $item->unit_snapshot));
                    $unit = $item->measurement_unit_id
                        ? $unitsById->get($item->measurement_unit_id)
                        : ($unitsByCode->get($snapshot) ?? $unitsBySymbol->get($snapshot));

                    if (! $unit && $ingredient?->measurement_unit_id) {
                        $unit = $unitsById->get($ingredient->measurement_unit_id);
                    }

                    $quantity = (float) ($item->requested_quantity ?? 0);
                    $quantityKg = (float) ($item->requested_quantity_kg ?? 0);
                    $ratio = $quantity > 0 && $quantityKg > 0
                        ? $quantityKg / $quantity
                        : 0.0;

                    $kgPerUnit = (float) ($item->kg_per_unit_snapshot ?? 0);

                    if ($kgPerUnit <= 0 && $ratio > 0) {
                        $kgPerUnit = $ratio;
                    }

                    if ($kgPerUnit <= 0 && $unit) {
                        $factor = (float) ($unit->to_base_factor ?? 0);
                        $gramsPerUnit = (float) ($ingredient?->grams_per_unit ?? 0);

                        $kgPerUnit = match ((string) $unit->unit_type) {
                            'weight' => $factor > 0 ? $factor / 1000 : 0,
                            'volume' => $factor > 0 ? $factor / 1000 : 0,
                            'count' => $gramsPerUnit > 0 ? $gramsPerUnit / 1000 : 0,
                            default => 0,
                        };
                    }

                    DB::table('procurement_request_items')
                        ->where('id', $item->id)
                        ->update([
                            'measurement_unit_id' => $unit?->id,
                            'kg_per_unit_snapshot' => $kgPerUnit > 0 ? round($kgPerUnit, 6) : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        Schema::table('procurement_request_items', function (Blueprint $table): void {
            if (Schema::hasColumn('procurement_request_items', 'measurement_unit_id')) {
                $table->dropConstrainedForeignId('measurement_unit_id');
            }

            if (Schema::hasColumn('procurement_request_items', 'kg_per_unit_snapshot')) {
                $table->dropColumn('kg_per_unit_snapshot');
            }
        });
    }
};
