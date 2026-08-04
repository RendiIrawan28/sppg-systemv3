<?php

namespace App\Services;

use App\Enums\ProcessingBatchState;
use App\Models\InventoryLot;
use App\Models\ProcessingBatch;
use App\Models\ProcessingMaterialUsage;
use App\Models\ProcessingReturn;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingReturnService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function submit(
        ProcessingBatch $batch,
        ProcessingMaterialUsage $usage,
        float $quantity,
        string $reason,
        User $actor,
    ): ProcessingReturn {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $usage, $quantity, $reason, $actor): ProcessingReturn {
            $batch = ProcessingBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $usage = ProcessingMaterialUsage::query()->lockForUpdate()->findOrFail($usage->id);

            if ($batch->state !== ProcessingBatchState::InProgress
                || $usage->processing_batch_id !== $batch->id) {
                throw ValidationException::withMessages([
                    'returnQuantity' => 'Bahan hanya dapat dikembalikan saat Pengolahan sedang dikerjakan.',
                ]);
            }

            $alreadyReturned = (float) ProcessingReturn::query()
                ->where('processing_material_usage_id', $usage->id)
                ->whereIn('status', [ProcessingReturn::WAITING, ProcessingReturn::VERIFIED])
                ->sum(DB::raw('COALESCE(actual_quantity, requested_quantity)'));

            if ($quantity <= 0 || $quantity > (float) $usage->quantity - $alreadyReturned + 0.0001) {
                throw ValidationException::withMessages([
                    'returnQuantity' => 'Jumlah retur tidak valid atau melebihi bahan yang belum diretur.',
                ]);
            }

            $sequence = ProcessingReturn::query()
                ->where('processing_batch_id', $batch->id)
                ->count() + 1;

            $return = ProcessingReturn::query()->create([
                'sppg_unit_id' => $batch->sppg_unit_id,
                'processing_batch_id' => $batch->id,
                'processing_material_usage_id' => $usage->id,
                'source_inventory_lot_id' => $usage->inventory_lot_id,
                'ingredient_id' => $usage->ingredient_id,
                'return_number' => $batch->batch_number.'/RET/'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                'return_date' => today(),
                'ingredient_name_snapshot' => $usage->material_name,
                'unit_snapshot' => $usage->unit_name,
                'requested_quantity' => $quantity,
                'condition_status' => 'good',
                'reason' => filled($reason)
                    ? trim($reason)
                    : 'Bahan tidak digunakan dan dikembalikan oleh Divisi Pengolahan.',
                'status' => ProcessingReturn::WAITING,
                'returned_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            $this->history($batch, $actor, 'return_submitted', $return);

            return $return;
        });
    }

    public function verify(
        ProcessingReturn $return,
        float $actualQuantity,
        string $disposition,
        ?string $notes,
        User $actor,
    ): ProcessingReturn {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $actualQuantity, $disposition, $notes, $actor): ProcessingReturn {
            $return = ProcessingReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ProcessingReturn::WAITING) {
                throw ValidationException::withMessages(['returnStatus' => 'Retur sudah diproses Gudang.']);
            }
            if ($actualQuantity <= 0
                || $actualQuantity > (float) $return->requested_quantity
                || ! in_array($disposition, ['available', 'quarantine', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'actualReturnQuantity' => 'Jumlah aktual atau keputusan Gudang tidak valid.',
                ]);
            }

            $destinationLot = $disposition === InventoryLot::AVAILABLE
                ? $this->restoreToSourceLot($return, $actualQuantity)
                : $this->createSeparatedReturnLot($return, $actualQuantity, $disposition);
            $destinationLot->loadMissing('ingredient.measurementUnit');
            $legacyKg = $this->units->legacyKilograms($destinationLot->ingredient, $actualQuantity);

            StockMovement::query()->create([
                'sppg_unit_id' => $return->sppg_unit_id,
                'ingredient_id' => $return->ingredient_id,
                'inventory_lot_id' => $destinationLot->id,
                'ingredient_name_snapshot' => $return->ingredient_name_snapshot,
                'unit_snapshot' => $return->unit_snapshot,
                'movement_type' => StockMovement::TYPE_RETURN_FROM_PROCESSING,
                'movement_date' => today(),
                'quantity_in_kg' => $legacyKg,
                'quantity_out_kg' => 0,
                'quantity_in' => $actualQuantity,
                'quantity_out' => 0,
                'source_type' => ProcessingReturn::class,
                'source_id' => $return->id,
                'reference_number' => $return->return_number,
                'supplier_batch_number' => $destinationLot->lot_number,
                'expired_date' => $destinationLot->expired_date,
                'notes' => filled($notes) ? trim($notes) : $return->reason,
                'created_by' => $actor->id,
            ]);

            $return->update([
                'destination_inventory_lot_id' => $destinationLot->id,
                'actual_quantity' => $actualQuantity,
                'warehouse_disposition' => $disposition,
                'warehouse_notes' => filled($notes) ? trim($notes) : null,
                'status' => ProcessingReturn::VERIFIED,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);
            $this->history($return->batch, $actor, 'return_verified', $return);

            return $return->refresh();
        });
    }

    public function reject(ProcessingReturn $return, string $notes, User $actor): ProcessingReturn
    {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $notes, $actor): ProcessingReturn {
            $return = ProcessingReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ProcessingReturn::WAITING || blank($notes)) {
                throw ValidationException::withMessages([
                    'returnStatus' => 'Alasan penolakan wajib diisi untuk retur yang masih menunggu.',
                ]);
            }
            $return->update([
                'warehouse_notes' => trim($notes),
                'status' => ProcessingReturn::REJECTED,
                'verified_by' => $actor->id,
                'rejected_at' => now(),
            ]);
            $this->history($return->batch, $actor, 'return_rejected', $return);

            return $return->refresh();
        });
    }

    private function restoreToSourceLot(ProcessingReturn $return, float $quantity): InventoryLot
    {
        $lot = InventoryLot::query()->with('ingredient.measurementUnit')->lockForUpdate()->find($return->source_inventory_lot_id);
        if (! $lot
            || $lot->sppg_unit_id !== $return->sppg_unit_id
            || $lot->ingredient_id !== $return->ingredient_id) {
            throw ValidationException::withMessages([
                'returnStatus' => 'Lot asal retur tidak ditemukan atau tidak sesuai.',
            ]);
        }
        if (in_array($lot->status, [InventoryLot::QUARANTINE, InventoryLot::REJECTED], true)) {
            throw ValidationException::withMessages([
                'returnStatus' => 'Lot asal sedang dikarantina atau ditolak. Pilih keputusan Karantina untuk retur ini.',
            ]);
        }

        $newBalance = (float) $lot->balance_quantity + $quantity;
        $lot->update([
            'balance_quantity' => $newBalance,
            'balance_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $newBalance),
            'status' => InventoryLot::AVAILABLE,
        ]);

        return $lot->refresh();
    }

    private function createSeparatedReturnLot(
        ProcessingReturn $return,
        float $quantity,
        string $disposition,
    ): InventoryLot {
        $source = InventoryLot::query()->with('ingredient.measurementUnit')->find($return->source_inventory_lot_id);
        $legacyKg = $this->units->legacyKilograms($source?->ingredient, $quantity);

        return InventoryLot::query()->create([
            'sppg_unit_id' => $return->sppg_unit_id,
            'ingredient_id' => $return->ingredient_id,
            'unit_snapshot' => $return->unit_snapshot,
            'initial_quantity' => $quantity,
            'balance_quantity' => $quantity,
            'lot_number' => ($source?->lot_number ?: 'TANPA-LOT').'-RET-PRO-'.$return->id,
            'expired_date' => $source?->expired_date,
            'location_name' => 'Area Retur Gudang',
            'storage_type' => $source?->storage_type ?: 'dry',
            'status' => $disposition,
            'initial_quantity_kg' => $legacyKg,
            'balance_quantity_kg' => $legacyKg,
        ]);
    }

    private function history(
        ProcessingBatch $batch,
        User $actor,
        string $action,
        ProcessingReturn $return,
    ): void {
        $batch->histories()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_state' => $batch->state->value,
            'to_state' => $batch->state->value,
            'notes' => $return->return_number,
            'snapshot' => $return->fresh()->toArray(),
        ]);
    }
}
