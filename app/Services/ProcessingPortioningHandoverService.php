<?php

namespace App\Services;

use App\Enums\PortioningSessionState;
use App\Enums\ProcessingBatchState;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingPortioningHandoverService
{
    public function completeAndHandover(ProcessingBatch $batch, User $actor): ProcessingBatch
    {
        abort_unless($actor->can('processing.update'), 403);
        $batch->refresh();
        if ($batch->state === ProcessingBatchState::InProgress) {
            $batch = app(ProcessingWorkflow::class)->complete($batch, $actor);
        }

        return DB::transaction(function () use ($batch, $actor): ProcessingBatch {
            $batch = ProcessingBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($batch->portioning_handed_over_at) return $batch;
            if ($batch->state !== ProcessingBatchState::Completed || (float) $batch->actual_output_quantity <= 0) {
                throw ValidationException::withMessages(['state' => 'Batch belum memenuhi syarat untuk diserahkan.']);
            }
            $batch->update([
                'portioning_handed_over_at' => now(),
                'portioning_handed_over_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $batch->histories()->create([
                'actor_id' => $actor->getKey(), 'action' => 'portioning_handed_over',
                'from_state' => $batch->state->value, 'to_state' => $batch->state->value,
                'snapshot' => $batch->fresh()->toArray(),
            ]);
            return $batch->refresh();
        });
    }

    public function receive(ProcessingBatch $batch, PortioningSession $session, User $actor): ProcessingBatch
    {
        abort_unless($actor->can('portioning.update'), 403);
        return DB::transaction(function () use ($batch, $session, $actor): ProcessingBatch {
            $batch = ProcessingBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $session = PortioningSession::query()->lockForUpdate()->findOrFail($session->getKey());
            if ($batch->sppg_unit_id !== $session->sppg_unit_id || ! $batch->portioning_handed_over_at) {
                throw ValidationException::withMessages(['batch' => 'Batch belum diserahkan ke Pemorsian.']);
            }
            if ($batch->portioning_received_at) {
                if ((int) $batch->portioning_session_id === (int) $session->getKey()) {
                    $this->syncPortioningSupply($batch, $session, $actor);

                    return $batch->refresh();
                }
                throw ValidationException::withMessages(['batch' => 'Batch sudah diterima sesi Pemorsian lain.']);
            }
            if (! in_array($session->state, [PortioningSessionState::Planned, PortioningSessionState::InProgress], true)) {
                throw ValidationException::withMessages(['session' => 'Sesi Pemorsian tidak dapat menerima batch.']);
            }
            if ($session->state === PortioningSessionState::Planned) {
                $session = app(PortioningWorkflow::class)->start($session, $actor);
            }
            $batch->update([
                'portioning_session_id' => $session->getKey(),
                'portioning_received_at' => now(),
                'portioning_received_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $this->syncPortioningSupply($batch, $session, $actor);
            $batch->histories()->create([
                'actor_id' => $actor->getKey(), 'action' => 'portioning_received',
                'from_state' => $batch->state->value, 'to_state' => $batch->state->value,
                'snapshot' => $batch->fresh()->toArray(),
            ]);
            return $batch->refresh();
        });
    }

    private function syncPortioningSupply(ProcessingBatch $batch, PortioningSession $session, User $actor): void
    {
        $existing = $session->supplies()
            ->where('source_type', 'processing_batch')
            ->where('source_id', $batch->getKey())
            ->first();
        $session->supplies()->updateOrCreate([
            'source_type' => 'processing_batch',
            'source_id' => $batch->getKey(),
        ], [
            'source_item_id' => $batch->getKey(),
            'supply_name' => $batch->product_name ?: $batch->menu_name_snapshot,
            'quantity' => $batch->actual_output_quantity,
            'unit_name' => $batch->actual_output_unit,
            'source_reference' => $batch->batch_number,
            'condition_status' => 'ready',
            'received_by' => $actor->getKey(),
            'received_at' => $batch->portioning_received_at ?: now(),
            'notes' => 'Hasil aktual batch Pengolahan yang diterima Pemorsian.',
            'sort_order' => $existing?->sort_order ?: $session->supplies()->count() + 1,
        ]);
    }
}
