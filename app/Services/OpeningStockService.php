<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\OpeningStock;
use App\Models\OpeningStockItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function create(int $unitId, string $date, string $photoPath, ?string $notes, array $rows, User $actor): OpeningStock
    {
        return DB::transaction(function () use ($unitId, $date, $photoPath, $notes, $rows, $actor): OpeningStock {
            $opening = OpeningStock::query()->create([
                'sppg_unit_id' => $unitId,
                'opening_number' => $this->nextNumber($unitId),
                'opening_date' => $date,
                'photo_path' => $photoPath,
                'notes' => trim((string) $notes) ?: null,
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ]);

            foreach (array_values($rows) as $index => $row) {
                $ingredient = $this->resolveIngredient($unitId, $row);
                $unit = $ingredient->measurementUnit()->firstOrFail();
                $unitSnapshot = $this->units->snapshot($unit);
                $quantity = (float) $row['quantity'];
                $legacyKg = $this->units->legacyKilogramsFromUnit($unit, $quantity);
                $lotNumber = trim((string) ($row['lot_number'] ?? '')) ?: "OPEN/{$opening->opening_number}/".($index + 1);

                $lot = InventoryLot::query()->create([
                    'sppg_unit_id' => $unitId,
                    'ingredient_id' => $ingredient->getKey(),
                    'unit_snapshot' => $unitSnapshot,
                    'initial_quantity' => $quantity,
                    'balance_quantity' => $quantity,
                    'initial_quantity_kg' => $legacyKg,
                    'balance_quantity_kg' => $legacyKg,
                    'lot_number' => $lotNumber,
                    'expired_date' => $row['expired_date'] ?: null,
                    'location_name' => trim((string) ($row['location_name'] ?? '')) ?: 'Gudang Utama',
                    'storage_type' => $row['storage_type'],
                    'status' => InventoryLot::AVAILABLE,
                ]);

                $item = OpeningStockItem::query()->create([
                    'opening_stock_id' => $opening->getKey(),
                    'ingredient_id' => $ingredient->getKey(),
                    'inventory_lot_id' => $lot->getKey(),
                    'ingredient_name_snapshot' => $ingredient->name,
                    'unit_snapshot' => $unitSnapshot,
                    'quantity' => $quantity,
                    'lot_number' => filled($row['lot_number'] ?? null) ? trim($row['lot_number']) : null,
                    'expired_date' => $row['expired_date'] ?: null,
                    'storage_type' => $row['storage_type'],
                    'location_name' => trim((string) ($row['location_name'] ?? '')) ?: 'Gudang Utama',
                    'condition_notes' => trim((string) ($row['condition_notes'] ?? '')) ?: null,
                ]);

                StockMovement::query()->create([
                    'sppg_unit_id' => $unitId,
                    'ingredient_id' => $ingredient->getKey(),
                    'inventory_lot_id' => $lot->getKey(),
                    'ingredient_name_snapshot' => $ingredient->name,
                    'unit_snapshot' => $unitSnapshot,
                    'movement_type' => StockMovement::TYPE_OPENING_BALANCE,
                    'movement_date' => $date,
                    'quantity_in_kg' => $legacyKg,
                    'quantity_out_kg' => 0,
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'source_type' => OpeningStockItem::class,
                    'source_id' => $item->getKey(),
                    'reference_number' => $opening->opening_number,
                    'supplier_batch_number' => $lotNumber,
                    'expired_date' => $row['expired_date'] ?: null,
                    'notes' => $item->condition_notes ?: 'Input stok awal gudang',
                    'created_by' => $actor->getKey(),
                ]);
            }

            return $opening->load(['items.ingredient', 'creator']);
        });
    }

    private function resolveIngredient(int $unitId, array $row): Ingredient
    {
        if (filled($row['ingredient_id'] ?? null)) {
            return Ingredient::query()
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->findOrFail($row['ingredient_id']);
        }

        if (blank($row['new_name'] ?? null) || blank($row['measurement_unit_id'] ?? null)) {
            throw ValidationException::withMessages(['rows' => 'Nama dan satuan wajib diisi untuk barang baru.']);
        }

        MeasurementUnit::query()->where('is_active', true)->findOrFail($row['measurement_unit_id']);
        $name = trim($row['new_name']);
        if (Ingredient::query()->where('sppg_unit_id', $unitId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['rows' => "Barang {$name} sudah tersedia pada Master Bahan. Pilih barang tersebut dari daftar."]);
        }

        return Ingredient::query()->create([
            'sppg_unit_id' => $unitId,
            'measurement_unit_id' => $row['measurement_unit_id'],
            'code' => $this->nextIngredientCode($unitId, $name),
            'name' => $name,
            'category' => $row['new_category'] ?: 'other',
            'edible_portion_percent' => 100,
            'loss_factor' => 1,
            'rounding_mode' => 'up',
            'nutrition_reference_grams' => 100,
            'is_active' => true,
        ]);
    }

    private function nextNumber(int $unitId): string
    {
        $prefix = 'SA/'.now()->format('Ymd').'/';
        $sequence = OpeningStock::query()->where('sppg_unit_id', $unitId)->where('opening_number', 'like', "{$prefix}%")->count() + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function nextIngredientCode(int $unitId, string $name): string
    {
        $base = 'STK-'.Str::upper(Str::slug($name, '-'));
        $base = Str::limit($base, 42, '');
        $code = $base;
        $sequence = 2;
        while (Ingredient::query()->where('sppg_unit_id', $unitId)->where('code', $code)->exists()) {
            $code = $base.'-'.$sequence++;
        }

        return $code;
    }
}
