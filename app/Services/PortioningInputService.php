<?php

namespace App\Services;

use App\Enums\PortioningSessionState;
use App\Models\PortioningSession;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortioningInputService
{
    public function syncWarehouseWithdrawal(WarehouseWithdrawal $withdrawal, User $actor): ?PortioningSession
    {
        if ($withdrawal->division_code !== 'pemorsian'
            || ! in_array($withdrawal->status, [WarehouseWithdrawal::WAITING, WarehouseWithdrawal::VERIFIED], true)) {
            return null;
        }

        return DB::transaction(function () use ($withdrawal, $actor): PortioningSession {
            $withdrawal = WarehouseWithdrawal::query()->with('items')->lockForUpdate()->findOrFail($withdrawal->id);
            $session = PortioningSession::query()
                ->where('sppg_unit_id', $withdrawal->sppg_unit_id)
                ->lockForUpdate()
                ->find($withdrawal->reference_id);
            if (! $session) {
                throw ValidationException::withMessages(['reference' => 'Sesi Pemorsian tidak ditemukan.']);
            }
            $sourceItemIds = $withdrawal->items->pluck('id');
            $knownItemIds = $session->supplies()
                ->where('source_type', 'warehouse_withdrawal')
                ->whereIn('source_item_id', $sourceItemIds)
                ->pluck('source_item_id');
            if ($sourceItemIds->diff($knownItemIds)->isNotEmpty()
                && ! in_array($session->state, [PortioningSessionState::Planned, PortioningSessionState::InProgress], true)) {
                throw ValidationException::withMessages(['reference' => 'Sesi Pemorsian sudah ditutup dan tidak dapat menerima bahan tambahan.']);
            }
            foreach ($withdrawal->items as $index => $item) {
                $session->supplies()->updateOrCreate([
                    'source_type' => 'warehouse_withdrawal',
                    'source_item_id' => $item->id,
                ], [
                    'source_id' => $withdrawal->id,
                    'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $item->inventory_lot_id,
                    'supply_name' => $item->ingredient_name_snapshot,
                    'quantity' => $item->actual_quantity ?? $item->requested_quantity ?? $item->verified_quantity_kg ?? $item->taken_quantity_kg,
                    'unit_name' => $item->unit_snapshot,
                    'source_reference' => $withdrawal->withdrawal_number,
                    'condition_status' => $item->condition_status,
                    'received_by' => $withdrawal->taken_by,
                    'received_at' => $withdrawal->verified_at ?? $withdrawal->submitted_at,
                    'notes' => $withdrawal->status === WarehouseWithdrawal::VERIFIED
                        ? 'Jumlah aktual telah diverifikasi Gudang.'
                        : 'Jumlah sementara dari pengambilan langsung Divisi Pemorsian.',
                    'sort_order' => $session->supplies()->count() + $index + 1,
                ]);
            }
            $this->history(
                $session,
                $actor,
                $withdrawal->status === WarehouseWithdrawal::VERIFIED ? 'warehouse_supply_verified' : 'warehouse_supply_recorded',
                $withdrawal->withdrawal_number,
            );

            return $session->refresh();
        });
    }

    private function history(PortioningSession $session, User $actor, string $action, string $reference): void
    {
        $session->histories()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'previous_state' => $session->state->value,
            'new_state' => $session->state->value,
            'notes' => $reference,
            'snapshot' => $session->fresh('supplies')->toArray(),
        ]);
    }
}
