<?php

namespace App\Services;

use App\Enums\PortioningSessionState;
use App\Models\InventoryLot;
use App\Models\PortioningReturn;
use App\Models\PortioningSession;
use App\Models\PortioningSupply;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Mobile\OperationalNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortioningReturnService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function submit(
        PortioningSession $session,
        PortioningSupply $supply,
        float $quantity,
        string $reason,
        ?string $photoPath,
        User $actor,
    ): PortioningReturn {
        abort_unless($actor->can('portioning.update'), 403);

        return DB::transaction(function () use ($session, $supply, $quantity, $reason, $photoPath, $actor): PortioningReturn {
            $session = PortioningSession::query()->lockForUpdate()->findOrFail($session->id);
            $supply = PortioningSupply::query()->lockForUpdate()->findOrFail($supply->id);

            if ($session->state !== PortioningSessionState::InProgress
                || $supply->portioning_session_id !== $session->id
                || $supply->source_type !== 'warehouse_withdrawal'
                || ! $supply->inventory_lot_id
                || ! $supply->ingredient_id) {
                throw ValidationException::withMessages([
                    'returnQuantity' => 'Hanya barang dari Gudang yang dapat diretur saat Pemorsian sedang berjalan.',
                ]);
            }

            $alreadyReturned = (float) PortioningReturn::query()
                ->where('portioning_supply_id', $supply->id)
                ->whereIn('status', [PortioningReturn::WAITING, PortioningReturn::VERIFIED])
                ->sum(DB::raw('COALESCE(actual_quantity, requested_quantity)'));
            $remaining = max(0, (float) $supply->quantity - $alreadyReturned);

            if ($quantity <= 0 || $quantity > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'returnQuantity' => 'Jumlah retur harus lebih dari nol dan tidak boleh melebihi sisa barang yang dapat diretur.',
                ]);
            }

            $sequence = PortioningReturn::query()
                ->where('portioning_session_id', $session->id)
                ->count() + 1;
            $return = PortioningReturn::query()->create([
                'sppg_unit_id' => $session->sppg_unit_id,
                'portioning_session_id' => $session->id,
                'portioning_supply_id' => $supply->id,
                'source_inventory_lot_id' => $supply->inventory_lot_id,
                'ingredient_id' => $supply->ingredient_id,
                'return_number' => $session->session_number.'/RET/'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                'return_date' => today(),
                'ingredient_name_snapshot' => $supply->supply_name,
                'unit_snapshot' => $supply->unit_name,
                'requested_quantity' => $quantity,
                'condition_status' => 'good',
                'reason' => filled($reason)
                    ? trim($reason)
                    : 'Barang tidak digunakan dan dikembalikan oleh Divisi Pemorsian.',
                'photo_path' => $photoPath,
                'status' => PortioningReturn::WAITING,
                'returned_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            $this->history($session, $actor, 'return_submitted', $return);
            $this->notifyWarehouse($return);

            return $return;
        });
    }

    public function verify(
        PortioningReturn $return,
        float $actualQuantity,
        string $disposition,
        ?string $notes,
        User $actor,
    ): PortioningReturn {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $actualQuantity, $disposition, $notes, $actor): PortioningReturn {
            $return = PortioningReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== PortioningReturn::WAITING) {
                throw ValidationException::withMessages(['returnStatus' => 'Retur Pemorsian sudah diproses Gudang.']);
            }
            if ($actualQuantity <= 0
                || $actualQuantity > (float) $return->requested_quantity
                || ! in_array($disposition, ['available', 'quarantine', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'actualReturnQuantity' => 'Jumlah aktual atau keputusan Gudang belum sesuai.',
                ]);
            }

            $destinationLot = $disposition === InventoryLot::AVAILABLE
                ? $this->restoreToSourceLot($return, $actualQuantity)
                : $this->createSeparatedReturnLot($return, $actualQuantity, $disposition);
            $destinationLot->loadMissing('ingredient.measurementUnit');
            $legacyKg = $this->units->legacyKilograms($destinationLot->ingredient, $actualQuantity);

            StockMovement::query()->create([
                'sppg_unit_id' => $return->sppg_unit_id,
                'warehouse_id' => $destinationLot->warehouse_id,
                'ingredient_id' => $return->ingredient_id,
                'inventory_lot_id' => $destinationLot->id,
                'ingredient_name_snapshot' => $return->ingredient_name_snapshot,
                'unit_snapshot' => $return->unit_snapshot,
                'movement_type' => StockMovement::TYPE_RETURN_FROM_PORTIONING,
                'movement_date' => today(),
                'quantity_in_kg' => $legacyKg,
                'quantity_out_kg' => 0,
                'quantity_in' => $actualQuantity,
                'quantity_out' => 0,
                'source_type' => PortioningReturn::class,
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
                'status' => PortioningReturn::VERIFIED,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);
            $this->history($return->session, $actor, 'return_verified', $return);
            $this->notifyRequester($return, true);

            return $return->refresh();
        });
    }

    public function reject(PortioningReturn $return, string $notes, User $actor): PortioningReturn
    {
        abort_unless($actor->can('stock.approve'), 403);

        return DB::transaction(function () use ($return, $notes, $actor): PortioningReturn {
            $return = PortioningReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== PortioningReturn::WAITING || blank($notes)) {
                throw ValidationException::withMessages([
                    'returnStatus' => 'Alasan penolakan wajib diisi untuk retur yang masih menunggu.',
                ]);
            }
            $return->update([
                'warehouse_notes' => trim($notes),
                'status' => PortioningReturn::REJECTED,
                'verified_by' => $actor->id,
                'rejected_at' => now(),
            ]);
            $this->history($return->session, $actor, 'return_rejected', $return);
            $this->notifyRequester($return, false);

            return $return->refresh();
        });
    }

    private function restoreToSourceLot(PortioningReturn $return, float $quantity): InventoryLot
    {
        $lot = InventoryLot::query()->with('ingredient.measurementUnit')->lockForUpdate()->find($return->source_inventory_lot_id);
        if (! $lot || $lot->sppg_unit_id !== $return->sppg_unit_id || $lot->ingredient_id !== $return->ingredient_id) {
            throw ValidationException::withMessages(['returnStatus' => 'Lot asal retur tidak ditemukan atau tidak sesuai.']);
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

    private function createSeparatedReturnLot(PortioningReturn $return, float $quantity, string $disposition): InventoryLot
    {
        $source = InventoryLot::query()->with('ingredient.measurementUnit')->find($return->source_inventory_lot_id);
        $legacyKg = $this->units->legacyKilograms($source?->ingredient, $quantity);

        return InventoryLot::query()->create([
            'sppg_unit_id' => $return->sppg_unit_id,
            'warehouse_id' => $source?->warehouse_id,
            'ingredient_id' => $return->ingredient_id,
            'unit_snapshot' => $return->unit_snapshot,
            'initial_quantity' => $quantity,
            'balance_quantity' => $quantity,
            'lot_number' => ($source?->lot_number ?: 'TANPA-LOT').'-RET-POR-'.$return->id,
            'expired_date' => $source?->expired_date,
            'location_name' => 'Area Retur Gudang',
            'storage_type' => $source?->storage_type ?: 'dry',
            'status' => $disposition,
            'initial_quantity_kg' => $legacyKg,
            'balance_quantity_kg' => $legacyKg,
        ]);
    }

    private function history(PortioningSession $session, User $actor, string $action, PortioningReturn $return): void
    {
        $session->histories()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'previous_state' => $session->state->value,
            'new_state' => $session->state->value,
            'notes' => $return->return_number,
            'snapshot' => $return->fresh()->toArray(),
        ]);
    }

    private function notifyWarehouse(PortioningReturn $return): void
    {
        app(OperationalNotificationService::class)->notifyPermissionAfterCommit(
            unitId: (int) $return->sppg_unit_id,
            permission: 'stock.approve',
            type: 'portioning_return_submitted',
            title: 'Retur Pemorsian Menunggu Verifikasi',
            message: "Pemorsian mengajukan retur {$return->ingredient_name_snapshot} {$return->requested_quantity} {$return->unit_snapshot}.",
            priority: 'important',
            module: 'warehouse',
            referenceType: 'portioning_return',
            referenceId: $return->getKey(),
            moduleSlug: 'gudang-retur-pemorsian',
            moduleLabel: 'Retur Pemorsian',
            eventVersion: PortioningReturn::WAITING,
        );
    }

    private function notifyRequester(PortioningReturn $return, bool $verified): void
    {
        if (! $return->returned_by) {
            return;
        }

        app(OperationalNotificationService::class)->notifyUsersAfterCommit(
            unitId: (int) $return->sppg_unit_id,
            userIds: [(int) $return->returned_by],
            type: $verified ? 'portioning_return_verified' : 'portioning_return_rejected',
            title: $verified ? 'Retur Pemorsian Diverifikasi' : 'Retur Pemorsian Ditolak',
            message: $verified
                ? "Retur {$return->ingredient_name_snapshot} telah diterima Gudang."
                : "Retur {$return->ingredient_name_snapshot} ditolak Gudang.",
            priority: $verified ? 'info' : 'important',
            module: 'portioning',
            referenceType: 'portioning_return',
            referenceId: $return->getKey(),
            moduleSlug: 'pemorsian',
            moduleLabel: 'Pemorsian',
            eventVersion: $verified ? PortioningReturn::VERIFIED : PortioningReturn::REJECTED,
            payload: ['record_id' => (string) $return->portioning_session_id],
        );
    }
}
