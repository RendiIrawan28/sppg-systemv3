<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockControlService
{
    public function create(InventoryLot $lot, float $actual, string $type, string $reason, User $actor): StockAdjustment
    {
        if ($actual < 0 || blank($reason)) {
            throw ValidationException::withMessages(['actualQuantity' => 'Jumlah aktual dan alasan wajib valid.']);
        }

        return StockAdjustment::create([
            'sppg_unit_id' => $lot->sppg_unit_id, 'inventory_lot_id' => $lot->id,
            'unit_snapshot' => $lot->unit_snapshot,
            'adjustment_number' => 'SA/'.now()->format('YmdHis').'/'.$lot->id, 'adjustment_date' => today(),
            'type' => $type, 'system_quantity' => $lot->balance_quantity, 'actual_quantity' => $actual,
            'difference_quantity' => $actual - (float) $lot->balance_quantity,
            'system_quantity_kg' => $lot->unit_snapshot === 'kg' ? $lot->balance_quantity : 0,
            'actual_quantity_kg' => $lot->unit_snapshot === 'kg' ? $actual : 0,
            'difference_quantity_kg' => $lot->unit_snapshot === 'kg' ? $actual - (float) $lot->balance_quantity : 0,
            'status' => StockAdjustment::DRAFT, 'reason' => $reason, 'created_by' => $actor->id,
        ]);
    }

    public function verify(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): StockAdjustment {
            $adjustment = StockAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== StockAdjustment::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Penyesuaian sudah diproses.']);
            }
            $lot = InventoryLot::lockForUpdate()->findOrFail($adjustment->inventory_lot_id);
            $difference = (float) $adjustment->actual_quantity - (float) $lot->balance_quantity;
            $lot->update([
                'balance_quantity' => $adjustment->actual_quantity,
                'balance_quantity_kg' => $lot->unit_snapshot === 'kg' ? $adjustment->actual_quantity : $lot->balance_quantity_kg,
                'status' => (float) $adjustment->actual_quantity > 0 ? InventoryLot::AVAILABLE : InventoryLot::DEPLETED,
            ]);
            StockMovement::create([
                'sppg_unit_id' => $lot->sppg_unit_id, 'ingredient_id' => $lot->ingredient_id, 'inventory_lot_id' => $lot->id,
                'ingredient_name_snapshot' => $lot->ingredient->name, 'unit_snapshot' => 'kg', 'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'movement_date' => today(),
                'quantity_in_kg' => $lot->unit_snapshot === 'kg' ? max(0, $difference) : 0,
                'quantity_out_kg' => $lot->unit_snapshot === 'kg' ? max(0, -$difference) : 0,
                'quantity_in' => max(0, $difference), 'quantity_out' => max(0, -$difference),
                'source_type' => StockAdjustment::class, 'source_id' => $adjustment->id, 'reference_number' => $adjustment->adjustment_number,
                'supplier_batch_number' => $lot->lot_number, 'expired_date' => $lot->expired_date, 'notes' => $adjustment->reason, 'created_by' => $actor->id,
            ]);
            $adjustment->update([
                'difference_quantity' => $difference,
                'difference_quantity_kg' => $lot->unit_snapshot === 'kg' ? $difference : 0,
                'status' => StockAdjustment::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now(),
            ]);

            return $adjustment->refresh();
        });
    }

    public function updateLot(InventoryLot $lot, string $location, string $storageType, string $status): void
    {
        if (! in_array($status, [InventoryLot::AVAILABLE, InventoryLot::QUARANTINE, InventoryLot::REJECTED, InventoryLot::DEPLETED], true)) {
            abort(422);
        }
        if (! in_array($storageType, ['wet', 'dry', 'freezer', 'chiller'], true)) {
            throw ValidationException::withMessages(['storageType' => 'Jenis penyimpanan tidak valid.']);
        }
        if ((float) $lot->balance_quantity <= 0 && $status !== InventoryLot::DEPLETED) {
            throw ValidationException::withMessages(['status' => 'Lot tanpa saldo harus berstatus Habis.']);
        }
        if ((float) $lot->balance_quantity > 0 && $status === InventoryLot::DEPLETED) {
            throw ValidationException::withMessages(['status' => 'Lot yang masih memiliki saldo tidak dapat ditandai Habis.']);
        }
        $lot->update(['location_name' => trim($location) ?: 'Gudang Utama', 'storage_type' => $storageType, 'status' => $status]);
    }
}
