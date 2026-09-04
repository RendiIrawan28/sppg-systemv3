<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only projection of the existing ledger. Never merges or rewrites lots. */
class WarehouseStockCardService
{
    public function movements(int $unitId, int $warehouseId, ?int $ingredientId = null): Builder
    {
        return StockMovement::query()->where('sppg_unit_id', $unitId)
            ->where('warehouse_id', $warehouseId)->whereNotNull('ingredient_id')
            ->whereNull('non_food_item_id')
            ->when($ingredientId !== null, fn ($q) => $q->where('ingredient_id', $ingredientId));
    }

    public function lots(int $unitId, int $warehouseId, ?int $ingredientId = null): Builder
    {
        return InventoryLot::query()->where('sppg_unit_id', $unitId)
            ->where('warehouse_id', $warehouseId)->whereNotNull('ingredient_id')
            ->whereNull('non_food_item_id')
            ->when($ingredientId !== null, fn ($q) => $q->where('ingredient_id', $ingredientId));
    }

    public function cards(int $unitId, int $warehouseId, string $search = '', string $status = '', ?int $ingredientId = null): Collection
    {
        $totals = $this->movements($unitId, $warehouseId, $ingredientId)
            ->select('ingredient_id', 'unit_snapshot')
            ->selectRaw('SUM(quantity_in) AS total_in, SUM(quantity_out) AS total_out, SUM(quantity_in_kg) AS in_kg, SUM(quantity_out_kg) AS out_kg, MAX(movement_date) AS last_movement_date, COUNT(*) AS movement_count')
            ->groupBy('ingredient_id', 'unit_snapshot')->get()->groupBy('ingredient_id');
        $lotTotals = $this->lots($unitId, $warehouseId, $ingredientId)
            ->select('ingredient_id')->selectRaw('MIN(id) AS representative_id')
            ->selectRaw('SUM(CASE WHEN status = ? AND balance_quantity > 0 THEN 1 ELSE 0 END) AS active_lot_count', [InventoryLot::AVAILABLE])
            ->groupBy('ingredient_id')->get()->keyBy('ingredient_id');
        $ids = $totals->keys()->merge($lotTotals->keys())->unique();
        $query = Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unitId)->whereIn('id', $ids);
        if (trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $matchingLots = $this->lots($unitId, $warehouseId)->where(fn ($q) => $q
                ->where('lot_number', 'like', $term)->orWhere('location_name', 'like', $term)->orWhere('storage_type', 'like', $term));
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term)
                ->orWhere('category', 'like', $term)->orWhereIn('id', $matchingLots->select('ingredient_id')));
        }
        if ($status !== '' && $status !== 'all') {
            $query->whereIn('id', $this->lots($unitId, $warehouseId)->where('status', $status)->select('ingredient_id'));
        }

        return $query->orderBy('name')->orderBy('id')->get()->map(function (Ingredient $ingredient) use ($totals, $lotTotals, $warehouseId): object {
            $rows = $totals->get($ingredient->id, collect());
            $unit = $ingredient->measurementUnit
                ? app(InventoryUnitService::class)->snapshot($ingredient->measurementUnit)
                : (string) ($rows->first()?->unit_snapshot ?: 'unit');
            $incoming = 0.0;
            $outgoing = 0.0;
            $valid = true;
            foreach ($rows as $row) {
                $in = app(InventoryUnitService::class)->stockCardQuantity($ingredient, $row->unit_snapshot, (float) $row->total_in, (float) $row->in_kg);
                $out = app(InventoryUnitService::class)->stockCardQuantity($ingredient, $row->unit_snapshot, (float) $row->total_out, (float) $row->out_kg);
                $valid = $valid && $in !== null && $out !== null;
                $incoming += $in ?? 0;
                $outgoing += $out ?? 0;
            }

            return (object) [
                'id' => (int) ($lotTotals->get($ingredient->id)?->representative_id ?? 0),
                'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouseId,
                'code' => $ingredient->code, 'ingredient_name_snapshot' => $ingredient->name,
                'unit_snapshot' => $unit, 'balance_quantity' => $valid ? round($incoming - $outgoing, 4) : null,
                'total_in' => $valid ? round($incoming, 4) : null, 'total_out' => $valid ? round($outgoing, 4) : null,
                'active_lot_count' => (int) ($lotTotals->get($ingredient->id)?->active_lot_count ?? 0),
                'movements_count' => (int) $rows->sum('movement_count'),
                'last_movement_date' => $rows->max('last_movement_date'),
                'conversion_warning' => $valid ? null : 'Satuan riwayat belum memiliki konversi yang sesuai. Saldo belum dapat direkap.',
            ];
        });
    }

    public function detailLots(int $unitId, int $warehouseId, int $ingredientId): Collection
    {
        return $this->lots($unitId, $warehouseId, $ingredientId)
            ->with('receiptItem.receipt')
            ->withMin(['movements as received_date' => fn ($query) => $query->where('quantity_in', '>', 0)], 'movement_date')
            ->orderBy('created_at')->orderBy('id')->get();
    }

    public function ledger(int $unitId, int $warehouseId, int $ingredientId): Collection
    {
        $ingredient = Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unitId)->findOrFail($ingredientId);
        $balance = 0.0;
        $valid = true;

        return $this->movements($unitId, $warehouseId, $ingredientId)
            ->orderBy('movement_date')->orderBy('created_at')->orderBy('id')->get()
            ->map(function (StockMovement $movement) use ($ingredient, &$balance, &$valid): StockMovement {
                $in = app(InventoryUnitService::class)->stockCardQuantity($ingredient, $movement->unit_snapshot, (float) $movement->quantity_in, (float) $movement->quantity_in_kg);
                $out = app(InventoryUnitService::class)->stockCardQuantity($ingredient, $movement->unit_snapshot, (float) $movement->quantity_out, (float) $movement->quantity_out_kg);
                $valid = $valid && $in !== null && $out !== null;
                $balance = round($balance + ($in ?? 0) - ($out ?? 0), 4);
                $movement->setAttribute('card_quantity_in', $in);
                $movement->setAttribute('card_quantity_out', $out);
                $movement->setAttribute('running_balance', $valid ? $balance : null);

                return $movement;
            });
    }
}
