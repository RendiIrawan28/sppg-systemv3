<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseWithdrawalService
{
    public function submit(WarehouseWithdrawal $withdrawal, User $actor): WarehouseWithdrawal
    {
        if (! $withdrawal->isEditable() || (int) $withdrawal->taken_by !== (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'Transaksi tidak dapat diajukan oleh pengguna ini.']);
        }
        if (! in_array($withdrawal->division_code, ['persiapan', 'pengolahan', 'pemorsian'], true) || ! $withdrawal->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'Divisi dan minimal satu bahan wajib diisi.']);
        }
        $withdrawal->update(['status' => WarehouseWithdrawal::WAITING, 'submitted_at' => now(), 'decision_notes' => null]);
        return $withdrawal->refresh();
    }

    public function verify(WarehouseWithdrawal $withdrawal, User $actor): WarehouseWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor): WarehouseWithdrawal {
            $withdrawal = WarehouseWithdrawal::query()->lockForUpdate()->with('items')->findOrFail($withdrawal->id);
            if ($withdrawal->status !== WarehouseWithdrawal::WAITING) {
                throw ValidationException::withMessages(['status' => 'Pengambilan tidak sedang menunggu verifikasi Gudang.']);
            }

            foreach ($withdrawal->items as $item) {
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($item->inventory_lot_id);
                $quantity = (float) ($item->verified_quantity_kg ?? $item->taken_quantity_kg);
                if ($lot->sppg_unit_id !== $withdrawal->sppg_unit_id || $lot->ingredient_id !== $item->ingredient_id) {
                    throw ValidationException::withMessages(['items' => "Lot untuk {$item->ingredient_name_snapshot} tidak sesuai."]);
                }
                if ($lot->status !== InventoryLot::AVAILABLE || ($lot->expired_date && $lot->expired_date->isBefore(today()))) {
                    throw ValidationException::withMessages(['items' => "Lot {$item->ingredient_name_snapshot} tidak tersedia atau kedaluwarsa."]);
                }
                if ($quantity <= 0 || $quantity > (float) $lot->balance_quantity_kg) {
                    throw ValidationException::withMessages(['items' => "Saldo lot {$item->ingredient_name_snapshot} tidak mencukupi."]);
                }

                $lot->balance_quantity_kg = (float) $lot->balance_quantity_kg - $quantity;
                if ((float) $lot->balance_quantity_kg <= 0.0001) $lot->status = InventoryLot::DEPLETED;
                $lot->save();
                $item->update(['verified_quantity_kg' => $quantity]);
                StockMovement::create([
                    'sppg_unit_id' => $withdrawal->sppg_unit_id, 'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $lot->id, 'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => 'kg', 'movement_type' => StockMovement::TYPE_HANDOVER,
                    'movement_date' => $withdrawal->withdrawal_date, 'quantity_in_kg' => 0,
                    'quantity_out_kg' => $quantity, 'source_type' => WarehouseWithdrawal::class,
                    'source_id' => $withdrawal->id, 'reference_number' => $withdrawal->withdrawal_number,
                    'supplier_batch_number' => $lot->lot_number, 'expired_date' => $lot->expired_date,
                    'notes' => 'Diambil langsung oleh Divisi '.ucfirst($withdrawal->division_code), 'created_by' => $actor->id,
                ]);
            }
            $withdrawal->update(['status' => WarehouseWithdrawal::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now()]);
            return $withdrawal->refresh();
        });
    }

    public function requestRevision(WarehouseWithdrawal $withdrawal, User $actor, string $reason): WarehouseWithdrawal
    {
        if ($withdrawal->status !== WarehouseWithdrawal::WAITING || blank($reason)) throw ValidationException::withMessages(['decisionNotes' => 'Alasan koreksi wajib diisi.']);
        $withdrawal->update(['status' => WarehouseWithdrawal::REVISION, 'verified_by' => $actor->id, 'decision_notes' => $reason]);
        return $withdrawal->refresh();
    }

    public function reject(WarehouseWithdrawal $withdrawal, User $actor, string $reason): WarehouseWithdrawal
    {
        if ($withdrawal->status !== WarehouseWithdrawal::WAITING || blank($reason)) throw ValidationException::withMessages(['decisionNotes' => 'Alasan penolakan wajib diisi.']);
        $withdrawal->update(['status' => WarehouseWithdrawal::REJECTED, 'verified_by' => $actor->id, 'rejected_at' => now(), 'decision_notes' => $reason]);
        return $withdrawal->refresh();
    }
}