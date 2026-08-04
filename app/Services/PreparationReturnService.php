<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationReturnService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function submit(
        PreparationSession $session,
        PreparationSessionItem $item,
        float $quantity,
        string $condition,
        string $reason,
        ?string $photoPath,
        User $actor,
    ): PreparationReturn {
        abort_unless($actor->can('preparation.update'), 403);

        return DB::transaction(function () use ($session, $item, $quantity, $condition, $reason, $photoPath, $actor): PreparationReturn {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->id);
            $item = PreparationSessionItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($session->state !== 'in_progress' || $item->preparation_session_id !== $session->id) {
                throw ValidationException::withMessages(['returnQuantity' => 'Retur hanya dapat diajukan saat Persiapan sedang dikerjakan.']);
            }
            if ($quantity <= 0 || blank($reason) || ! in_array($condition, ['good', 'damaged', 'rejected'], true)) {
                throw ValidationException::withMessages(['returnQuantity' => 'Jumlah, kondisi, dan alasan retur wajib valid.']);
            }

            $received = (float) ($item->received_quantity ?? $item->received_weight_kg);
            $alreadyReturned = (float) PreparationReturn::query()
                ->where('preparation_session_item_id', $item->id)
                ->whereIn('status', [PreparationReturn::WAITING, PreparationReturn::VERIFIED])
                ->sum(DB::raw('COALESCE(actual_quantity, requested_quantity)'));
            if ($quantity > $received - $alreadyReturned + 0.0001) {
                throw ValidationException::withMessages(['returnQuantity' => 'Jumlah retur melebihi bahan yang diterima dan belum diretur.']);
            }

            $sequence = PreparationReturn::query()->where('preparation_session_id', $session->id)->count() + 1;

            $return = PreparationReturn::query()->create([
                'sppg_unit_id' => $session->sppg_unit_id,
                'preparation_session_id' => $session->id,
                'preparation_session_item_id' => $item->id,
                'source_inventory_lot_id' => $item->inventory_lot_id,
                'ingredient_id' => $item->ingredient_id,
                'return_number' => $session->session_number.'/RET/'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                'return_date' => today(),
                'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                'unit_snapshot' => $item->unit_snapshot,
                'requested_quantity' => $quantity,
                'condition_status' => $condition,
                'reason' => trim($reason),
                'photo_path' => $photoPath,
                'status' => PreparationReturn::WAITING,
                'returned_by' => $actor->id,
                'submitted_at' => now(),
            ]);
            $this->history($session, $actor, 'return_submitted', $return);

            return $return;
        });
    }

    public function verify(
        PreparationReturn $return,
        float $actualQuantity,
        string $disposition,
        ?string $notes,
        User $actor,
    ): PreparationReturn {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $actualQuantity, $disposition, $notes, $actor): PreparationReturn {
            $return = PreparationReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== PreparationReturn::WAITING) {
                throw ValidationException::withMessages(['returnStatus' => 'Retur sudah diproses Gudang.']);
            }
            if ($actualQuantity <= 0 || $actualQuantity > (float) $return->requested_quantity || ! in_array($disposition, ['available', 'quarantine', 'rejected'], true)) {
                throw ValidationException::withMessages(['actualReturnQuantity' => 'Jumlah aktual atau keputusan Gudang tidak valid.']);
            }

            $destinationLot = $disposition === 'available'
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
                'movement_type' => StockMovement::TYPE_RETURN_FROM_PREPARATION,
                'movement_date' => today(),
                'quantity_in_kg' => $legacyKg,
                'quantity_out_kg' => 0,
                'quantity_in' => $actualQuantity,
                'quantity_out' => 0,
                'source_type' => PreparationReturn::class,
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
                'status' => PreparationReturn::VERIFIED,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);
            $this->history($return->session, $actor, 'return_verified', $return);

            return $return->refresh();
        });
    }

    public function reject(PreparationReturn $return, string $notes, User $actor): PreparationReturn
    {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $notes, $actor): PreparationReturn {
            $return = PreparationReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== PreparationReturn::WAITING || blank($notes)) {
                throw ValidationException::withMessages(['returnStatus' => 'Alasan penolakan wajib diisi untuk retur yang masih menunggu.']);
            }
            $return->update([
                'warehouse_notes' => trim($notes),
                'status' => PreparationReturn::REJECTED,
                'verified_by' => $actor->id,
                'rejected_at' => now(),
            ]);
            $this->history($return->session, $actor, 'return_rejected', $return);

            return $return->refresh();
        });
    }

    private function restoreToSourceLot(PreparationReturn $return, float $quantity): InventoryLot
    {
        $lot = InventoryLot::query()->with('ingredient.measurementUnit')->lockForUpdate()->find($return->source_inventory_lot_id);
        if (! $lot || $lot->sppg_unit_id !== $return->sppg_unit_id || $lot->ingredient_id !== $return->ingredient_id) {
            throw ValidationException::withMessages(['returnStatus' => 'Lot asal retur tidak ditemukan atau tidak sesuai.']);
        }
        if (in_array($lot->status, [InventoryLot::QUARANTINE, InventoryLot::REJECTED], true)) {
            throw ValidationException::withMessages(['returnStatus' => 'Lot asal sedang dikarantina atau ditolak. Pilih keputusan Karantina untuk retur ini.']);
        }

        $newBalance = (float) $lot->balance_quantity + $quantity;
        $lot->update([
            'balance_quantity' => $newBalance,
            'balance_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $newBalance),
            'status' => InventoryLot::AVAILABLE,
        ]);

        return $lot->refresh();
    }

    private function createSeparatedReturnLot(PreparationReturn $return, float $quantity, string $disposition): InventoryLot
    {
        $source = InventoryLot::query()->with('ingredient.measurementUnit')->find($return->source_inventory_lot_id);
        $legacyKg = $this->units->legacyKilograms($source?->ingredient, $quantity);

        return InventoryLot::query()->create([
            'sppg_unit_id' => $return->sppg_unit_id,
            'ingredient_id' => $return->ingredient_id,
            'unit_snapshot' => $return->unit_snapshot,
            'initial_quantity' => $quantity,
            'balance_quantity' => $quantity,
            'lot_number' => ($source?->lot_number ?: 'TANPA-LOT').'-RET-'.$return->id,
            'expired_date' => $source?->expired_date,
            'location_name' => 'Area Retur Gudang',
            'storage_type' => $source?->storage_type ?: 'dry',
            'status' => $disposition,
            'initial_quantity_kg' => $legacyKg,
            'balance_quantity_kg' => $legacyKg,
        ]);
    }

    private function history(PreparationSession $session, User $actor, string $action, PreparationReturn $return): void
    {
        $session->histories()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'notes' => $return->return_number,
            'snapshot' => $return->fresh()->toArray(),
        ]);
    }
}
