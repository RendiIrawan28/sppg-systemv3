<?php

namespace App\Services;

use App\Enums\ProcessingBatchState;
use App\Models\FieldDistributionPlan;
use App\Models\PreparationMaterialHandover;
use App\Models\ProcessingBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PreparationToProcessingBatchService
{
    public function attachToExistingBatch(
        PreparationMaterialHandover $handover,
        FieldDistributionPlan $plan,
        User $actor,
    ): ProcessingBatch {
        if (! in_array($handover->status, [
            PreparationMaterialHandover::STATUS_PREPARED,
            PreparationMaterialHandover::STATUS_WASTE_RECORDED,
        ], true)) {
            throw new InvalidArgumentException('Bahan hanya dapat diserahkan ke Pengolahan setelah diproses oleh tim Persiapan.');
        }

        $handover->load('items');

        if ($handover->items->isEmpty()) {
            throw new InvalidArgumentException('Dokumen Persiapan belum memiliki rincian bahan.');
        }

        if ((int) $plan->sppg_unit_id !== (int) $handover->sppg_unit_id) {
            throw new InvalidArgumentException('Rencana distribusi tidak berasal dari unit SPPG yang sama.');
        }

        return DB::transaction(function () use ($handover, $plan, $actor): ProcessingBatch {
            $batch = $this->resolveExistingBatch($plan);

            if (! $batch) {
                throw new InvalidArgumentException('Batch pengolahan belum dibuat. Jalankan tombol Buat Rencana Operasional dari Perencanaan Distribusi terlebih dahulu.');
            }

            if ((int) $batch->sppg_unit_id !== (int) $handover->sppg_unit_id) {
                throw new InvalidArgumentException('Batch pengolahan tidak berasal dari unit SPPG yang sama.');
            }

            if (! $this->batchStillReceivesMaterial($batch)) {
                throw new InvalidArgumentException('Batch pengolahan sudah berjalan atau sudah dikunci, sehingga bahan tidak dapat dihubungkan lagi.');
            }

            $marker = "[handover:{$handover->id}]";

            $batch->materialUsages()
                ->where('notes', 'like', "%{$marker}%")
                ->delete();

            $nextSortOrder = (int) $batch->materialUsages()->max('sort_order');

            foreach ($handover->items as $item) {
                $quantity = (float) ($item->prepared_quantity ?: $item->good_quantity ?: $item->handed_over_quantity ?: 0);

                if ($quantity <= 0) {
                    continue;
                }

                $nextSortOrder++;

                $batch->materialUsages()->create([
                    'ingredient_id' => $item->ingredient_id,
                    'material_name' => $item->ingredient_name_snapshot,
                    'quantity' => $quantity,
                    'measurement_unit_id' => null,
                    'unit_name' => $item->unit_snapshot ?: 'unit',
                    'sort_order' => $nextSortOrder,
                    'notes' => trim(implode(' | ', array_filter([
                        $marker,
                        'Serah persiapan: '.$handover->handover_number,
                        $item->supplier_batch_number ? 'Batch supplier: '.$item->supplier_batch_number : null,
                        $item->expired_date ? 'Expired: '.$item->expired_date->format('d-m-Y') : null,
                        $item->preparation_notes ?: $item->notes,
                    ]))),
                ]);
            }

            $notes = trim(implode("\n", array_filter([
                $batch->notes,
                "Bahan siap olah dari dokumen persiapan {$handover->handover_number} sudah diserahkan ke batch.",
            ])));

            $batch->forceFill([
                'preparation_material_handover_id' => $handover->id,
                'notes' => $notes,
                'updated_by' => $actor->id,
            ])->save();

            $plan->forceFill([
                'processing_batch_id' => $batch->id,
                'updated_by' => $actor->id,
            ])->save();

            $handover->forceFill([
                'field_distribution_plan_id' => $plan->id,
                'processing_batch_id' => $batch->id,
                'status' => PreparationMaterialHandover::STATUS_HANDED_OVER_TO_PROCESSING,
                'handed_over_to_processing_by' => $actor->id,
                'handed_over_to_processing_at' => now(),
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            return $batch->refresh();
        });
    }

    /**
     * Backward-compatible method name. Alur baru tidak membuat batch dari serah bahan.
     */
    public function createFromHandover(
        PreparationMaterialHandover $handover,
        ?FieldDistributionPlan $plan,
        User $actor,
    ): ProcessingBatch {
        if (! $plan) {
            throw new InvalidArgumentException('Pilih Rencana Distribusi yang sudah memiliki batch operasional. Batch tidak lagi dibuat dari serah bahan.');
        }

        return $this->attachToExistingBatch($handover, $plan, $actor);
    }

    private function resolveExistingBatch(FieldDistributionPlan $plan): ?ProcessingBatch
    {
        if ($plan->processing_batch_id) {
            $batch = ProcessingBatch::query()->find($plan->processing_batch_id);

            if ($batch) {
                return $batch;
            }
        }

        return ProcessingBatch::query()
            ->where('field_distribution_plan_id', $plan->id)
            ->first();
    }

    private function batchStillReceivesMaterial(ProcessingBatch $batch): bool
    {
        $state = $batch->state;
        $stateValue = $state instanceof ProcessingBatchState ? $state->value : (string) $state;

        return $stateValue === ProcessingBatchState::Planned->value;
    }
}
