<?php

namespace App\Services;

use App\Enums\ProcessingBatchState;
use App\Models\ProcessingBatch;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingInputService
{
    public function syncWarehouseWithdrawal(WarehouseWithdrawal $withdrawal, User $actor): ?ProcessingBatch
    {
        if ($withdrawal->division_code !== 'pengolahan'
            || ! in_array($withdrawal->status, [WarehouseWithdrawal::WAITING, WarehouseWithdrawal::VERIFIED], true)) {
            return null;
        }

        return DB::transaction(function () use ($withdrawal, $actor): ProcessingBatch {
            $withdrawal = WarehouseWithdrawal::query()->with('items')->lockForUpdate()->findOrFail($withdrawal->id);
            $batch = ProcessingBatch::query()->where('sppg_unit_id', $withdrawal->sppg_unit_id)->lockForUpdate()->find($withdrawal->reference_id);
            if (! $batch) {
                throw ValidationException::withMessages(['reference' => 'Batch Pengolahan untuk pengambilan langsung tidak ditemukan.']);
            }
            $sourceItemIds = $withdrawal->items->pluck('id');
            $knownItemIds = $batch->materialUsages()
                ->where('source_type', 'warehouse_withdrawal')
                ->whereIn('source_item_id', $sourceItemIds)
                ->pluck('source_item_id');
            if ($sourceItemIds->diff($knownItemIds)->isNotEmpty()) {
                $this->assertReceivesInput($batch);
            }

            foreach ($withdrawal->items as $index => $item) {
                $batch->materialUsages()->updateOrCreate([
                    'source_type' => 'warehouse_withdrawal',
                    'source_item_id' => $item->id,
                ], [
                    'source_id' => $withdrawal->id,
                    'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $item->inventory_lot_id,
                    'material_name' => $item->ingredient_name_snapshot,
                    'quantity' => $item->actual_quantity ?? $item->requested_quantity ?? $item->verified_quantity_kg ?? $item->taken_quantity_kg,
                    'unit_name' => $item->unit_snapshot,
                    'source_reference' => $withdrawal->withdrawal_number,
                    'condition_status' => $item->condition_status,
                    'received_by' => $withdrawal->taken_by,
                    'received_at' => $withdrawal->verified_at ?? $withdrawal->submitted_at,
                    'notes' => $withdrawal->status === WarehouseWithdrawal::VERIFIED
                        ? 'Jumlah aktual telah diverifikasi Gudang.'
                        : 'Jumlah sementara dari pengambilan langsung Divisi Pengolahan.',
                    'sort_order' => $batch->materialUsages()->count() + $index + 1,
                ]);
            }
            $this->history(
                $batch,
                $actor,
                $withdrawal->status === WarehouseWithdrawal::VERIFIED ? 'warehouse_material_verified' : 'warehouse_material_recorded',
                $withdrawal->withdrawal_number,
            );

            return $batch->refresh();
        });
    }

    private function assertReceivesInput(ProcessingBatch $batch): void
    {
        if (! in_array($batch->state, [ProcessingBatchState::Planned, ProcessingBatchState::InProgress], true)) {
            throw ValidationException::withMessages(['state' => 'Batch Pengolahan sudah ditutup dan tidak dapat menerima bahan tambahan.']);
        }
    }

    private function history(ProcessingBatch $batch, User $actor, string $action, string $reference): void
    {
        $batch->histories()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_state' => $batch->state->value,
            'to_state' => $batch->state->value,
            'notes' => $reference,
            'snapshot' => $batch->fresh('materialUsages')->toArray(),
        ]);
    }
}
