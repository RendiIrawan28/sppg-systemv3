<?php

namespace App\Services;

use App\Models\ProcessingBatch;
use App\Models\User;
use App\Models\WarehouseWithdrawal;

class ProcessingInputService
{
    public function syncWarehouseWithdrawal(
        WarehouseWithdrawal $withdrawal,
        User $actor,
    ): ?ProcessingBatch {
        if ($withdrawal->division_code !== 'pengolahan'
            || $withdrawal->status !== WarehouseWithdrawal::VERIFIED) {
            return null;
        }

        app(ProcessingMaterialStockService::class)
            ->receiveWarehouseWithdrawal($withdrawal, $actor);

        // reference_id tetap dipertahankan sebagai jejak batch yang menjadi alasan
        // pengambilan. Stok yang diterima tidak dikunci pada batch tersebut.
        $batch = $withdrawal->reference_type === 'processing_batch'
            ? ProcessingBatch::query()
                ->where('sppg_unit_id', $withdrawal->sppg_unit_id)
                ->find($withdrawal->reference_id)
            : null;

        if ($batch) {
            $batch->histories()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'warehouse_material_added_to_processing_stock',
                'from_state' => $batch->state->value,
                'to_state' => $batch->state->value,
                'notes' => $withdrawal->withdrawal_number,
                'snapshot' => $withdrawal->fresh('items')->toArray(),
            ]);
        }

        return $batch?->refresh();
    }
}
