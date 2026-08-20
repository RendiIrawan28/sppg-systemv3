<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\OpeningStock;
use App\Models\OpeningStockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function create(int $unitId, string $date, string $photoPath, ?string $notes, array $rows, User $actor): OpeningStock
    {
        return $this->createForWarehouse($unitId, $date, $photoPath, $notes, $rows, $actor, Warehouse::TYPE_FOOD);
    }

    public function createForWarehouse(int $unitId, string $date, string $photoPath, ?string $notes, array $rows, User $actor, string $warehouseType): OpeningStock
    {
        return DB::transaction(function () use ($unitId, $date, $photoPath, $notes, $rows, $actor, $warehouseType): OpeningStock {
            $warehouse = Warehouse::forUnit($unitId, $warehouseType);
            $opening = OpeningStock::query()->create([
                'sppg_unit_id' => $unitId,
                'warehouse_id' => $warehouse->getKey(),
                'opening_number' => $this->nextNumber($unitId),
                'opening_date' => $date,
                'photo_path' => $photoPath,
                'notes' => trim((string) $notes) ?: null,
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ]);

            foreach (array_values($rows) as $index => $row) {
                $stockItem = $warehouseType === Warehouse::TYPE_NON_FOOD
                    ? $this->resolveNonFoodItem($unitId, $row)
                    : $this->resolveIngredient($unitId, $row);
                $unit = $stockItem->measurementUnit()->firstOrFail();
                $unitSnapshot = $this->units->snapshot($unit);
                $quantity = (float) $row['quantity'];
                $legacyKg = $warehouseType === Warehouse::TYPE_FOOD
                    ? $this->units->legacyKilogramsFromUnit($unit, $quantity)
                    : 0.0;
                $lotNumber = trim((string) ($row['lot_number'] ?? '')) ?: "OPEN/{$opening->opening_number}/".($index + 1);

                if ($stockItem instanceof NonFoodItem) {
                    if ($stockItem->tracks_lot && blank($row['lot_number'] ?? null)) {
                        throw ValidationException::withMessages(['rows' => "Nomor lot {$stockItem->name} wajib diisi."]);
                    }
                    if ($stockItem->tracks_expiry && blank($row['expired_date'] ?? null)) {
                        throw ValidationException::withMessages(['rows' => "Tanggal kedaluwarsa {$stockItem->name} wajib diisi."]);
                    }
                }

                $lot = InventoryLot::query()->create([
                    'sppg_unit_id' => $unitId,
                    'warehouse_id' => $warehouse->getKey(),
                    'ingredient_id' => $stockItem instanceof Ingredient ? $stockItem->getKey() : null,
                    'non_food_item_id' => $stockItem instanceof NonFoodItem ? $stockItem->getKey() : null,
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
                    'ingredient_id' => $stockItem instanceof Ingredient ? $stockItem->getKey() : null,
                    'non_food_item_id' => $stockItem instanceof NonFoodItem ? $stockItem->getKey() : null,
                    'inventory_lot_id' => $lot->getKey(),
                    'ingredient_name_snapshot' => $stockItem->name,
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
                    'warehouse_id' => $warehouse->getKey(),
                    'ingredient_id' => $stockItem instanceof Ingredient ? $stockItem->getKey() : null,
                    'non_food_item_id' => $stockItem instanceof NonFoodItem ? $stockItem->getKey() : null,
                    'inventory_lot_id' => $lot->getKey(),
                    'ingredient_name_snapshot' => $stockItem->name,
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

            return $opening->load(['warehouse', 'items.ingredient', 'items.nonFoodItem', 'creator']);
        });
    }

    private function resolveNonFoodItem(int $unitId, array $row): NonFoodItem
    {
        if (filled($row['non_food_item_id'] ?? null)) {
            return NonFoodItem::query()->forUnit($unitId)->where('is_active', true)->findOrFail($row['non_food_item_id']);
        }

        if (blank($row['new_name'] ?? null) || blank($row['measurement_unit_id'] ?? null)) {
            throw ValidationException::withMessages(['rows' => 'Nama dan satuan wajib diisi untuk barang Non-Pangan baru.']);
        }

        MeasurementUnit::query()->where('is_active', true)->findOrFail($row['measurement_unit_id']);
        $name = trim($row['new_name']);
        if (NonFoodItem::query()->forUnit($unitId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['rows' => "Barang Non-Pangan {$name} sudah tersedia pada master."]);
        }

        return NonFoodItem::query()->create([
            'sppg_unit_id' => $unitId,
            'measurement_unit_id' => $row['measurement_unit_id'],
            'code' => $this->nextNonFoodCode($unitId, $name),
            'name' => $name,
            'category' => $row['new_category'] ?: 'Lainnya',
            'minimum_stock' => (float) ($row['minimum_stock'] ?? 0),
            'target_stock' => (float) ($row['target_stock'] ?? 0),
            'default_location' => trim((string) ($row['location_name'] ?? '')) ?: 'Gudang Non-Pangan',
            'tracks_lot' => (bool) ($row['tracks_lot'] ?? false),
            'tracks_expiry' => (bool) ($row['tracks_expiry'] ?? false),
            'is_active' => true,
        ]);
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

    private function nextNonFoodCode(int $unitId, string $name): string
    {
        $base = Str::limit('NP-'.Str::upper(Str::slug($name, '-')), 52, '');
        $code = $base;
        $sequence = 2;
        while (NonFoodItem::query()->forUnit($unitId)->where('code', $code)->exists()) {
            $code = $base.'-'.$sequence++;
        }

        return $code;
    }
}
