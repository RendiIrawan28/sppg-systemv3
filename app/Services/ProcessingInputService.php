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
            || ! in_array($withdrawal->status, [
                WarehouseWithdrawal::WAITING,
                WarehouseWithdrawal::VERIFIED,
            ], true)) {
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
            $action = $withdrawal->status === WarehouseWithdrawal::VERIFIED
                ? 'warehouse_material_verified'
                : 'warehouse_material_available';
            $batch->histories()->create([
                'actor_id' => $actor->getKey(),
                'action' => $action,
                'from_state' => $batch->state->value,
                'to_state' => $batch->state->value,
                'notes' => $withdrawal->withdrawal_number.($withdrawal->status === WarehouseWithdrawal::VERIFIED
                    ? ' · jumlah aktual diverifikasi Gudang'
                    : ' · langsung tersedia sambil menunggu verifikasi Gudang'),
                'snapshot' => $withdrawal->fresh('items')->toArray(),
            ]);
        }

        return $batch?->refresh();
    }
}
