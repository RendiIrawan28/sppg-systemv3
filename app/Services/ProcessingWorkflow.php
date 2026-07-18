<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingDeviationSeverity;
use App\Enums\ProcessingDeviationStatus;
use App\Models\ProcessingBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingWorkflow
{
    public function start(ProcessingBatch $batch, User $actor): ProcessingBatch
    {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $actor): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if ($batch->state !== ProcessingBatchState::Planned || ! $batch->isReportEditable()) {
                throw ValidationException::withMessages([
                    'state' => 'Batch tidak dapat dimulai pada status saat ini.',
                ]);
            }

            $fromState = $batch->state->value;

            $batch->update([
                'state' => ProcessingBatchState::InProgress,
                'started_at' => $batch->started_at ?? now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($batch, $actor, 'production_started', $fromState, ProcessingBatchState::InProgress->value);

            return $batch->refresh();
        });
    }

    public function complete(ProcessingBatch $batch, User $actor): ProcessingBatch
    {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $actor): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if ($batch->state !== ProcessingBatchState::InProgress || ! $batch->isReportEditable()) {
                throw ValidationException::withMessages([
                    'state' => 'Hanya batch yang sedang diproses yang dapat diselesaikan.',
                ]);
            }

            $this->validateBeforeComplete($batch);
            $fromState = $batch->state->value;

            $batch->update([
                'state' => ProcessingBatchState::Completed,
                'completed_at' => $batch->completed_at ?? now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($batch, $actor, 'production_completed', $fromState, ProcessingBatchState::Completed->value);

            return $batch->refresh();
        });
    }

    public function handover(
        ProcessingBatch $batch,
        User $actor,
        array $data,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $actor, $data): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if ($batch->state !== ProcessingBatchState::Completed || ! $batch->isReportEditable()) {
                throw ValidationException::withMessages([
                    'state' => 'Serah-terima hanya dapat dilakukan setelah produksi selesai.',
                ]);
            }

            $quantity = (float) ($data['output_quantity'] ?? 0);

            if ($quantity <= 0 || blank($data['unit_name'] ?? null) || blank($data['received_by_name'] ?? null)) {
                throw ValidationException::withMessages([
                    'handover' => 'Jumlah hasil, satuan, dan penerima serah-terima wajib diisi.',
                ]);
            }

            $batch->handover()->updateOrCreate(
                ['processing_batch_id' => $batch->getKey()],
                [
                    'handed_over_at' => $data['handed_over_at'] ?? now(),
                    'output_quantity' => $quantity,
                    'unit_name' => $data['unit_name'],
                    'received_by_user_id' => $data['received_by_user_id'] ?? null,
                    'received_by_name' => $data['received_by_name'],
                    'notes' => $data['notes'] ?? null,
                    'photo_path' => $data['photo_path'] ?? null,
                    'created_by' => $actor->getKey(),
                ],
            );

            $fromState = $batch->state->value;

            $batch->update([
                'state' => ProcessingBatchState::HandedOver,
                'actual_output_quantity' => $quantity,
                'actual_output_unit' => $data['unit_name'],
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $batch,
                $actor,
                'handed_over_to_portioning',
                $fromState,
                ProcessingBatchState::HandedOver->value,
                $data['notes'] ?? null,
            );

            return $batch->refresh();
        });
    }

    public function submit(
        ProcessingBatch $batch,
        User $actor,
        ?string $notes = null,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $actor, $notes): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if (! $batch->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Batch belum dapat diajukan. Pastikan hasil sudah diserahkan ke Pemorsian.',
                ]);
            }

            $this->validateBeforeSubmit($batch);
            $fromStatus = $batch->status->value;

            $batch->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $batch,
                $actor,
                'submitted',
                $batch->state->value,
                $batch->state->value,
                $notes,
                $fromStatus,
                OperationalReportStatus::Submitted->value,
            );

            return $batch->refresh();
        });
    }

    public function verify(
        ProcessingBatch $batch,
        User $actor,
        ?string $notes = null,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.approve'), 403);

        return DB::transaction(function () use ($batch, $actor, $notes): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if (! app(OperationalReportApprovalService::class)->isReviewable($batch->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($batch->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);
            $fromStatus = $batch->status->value;

            $batch->update([
                'status' => $nextStatus,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $batch,
                $actor,
                $action,
                $batch->state->value,
                $batch->state->value,
                $notes,
                $fromStatus,
                $nextStatus->value,
            );

            return $batch->refresh();
        });
    }

    public function requestRevision(
        ProcessingBatch $batch,
        User $actor,
        string $notes,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.approve'), 403);

        return DB::transaction(function () use ($batch, $actor, $notes): ProcessingBatch {
            $batch = $this->lockedBatch($batch);

            if (! app(OperationalReportApprovalService::class)->isReviewable($batch->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak sedang menunggu verifikasi.',
                ]);
            }

            $batch->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $batch,
                $actor,
                'revision_requested',
                $batch->state->value,
                $batch->state->value,
                $notes,
                OperationalReportStatus::Submitted->value,
                OperationalReportStatus::RevisionRequired->value,
            );

            return $batch->refresh();
        });
    }

    private function lockedBatch(ProcessingBatch $batch): ProcessingBatch
    {
        return ProcessingBatch::query()
            ->with([
                'materialUsages',
                'temperatureLogs',
                'documentations',
                'deviations',
                'handover',
            ])
            ->lockForUpdate()
            ->findOrFail($batch->getKey());
    }

    private function validateBeforeComplete(ProcessingBatch $batch): void
    {
        $errors = [];

        if ($batch->materialUsages->isEmpty()) {
            $errors['materialUsages'] = 'Minimal satu bahan baku harus dicatat.';
        }

        if ((float) $batch->actual_output_quantity <= 0) {
            $errors['actual_output_quantity'] = 'Hasil akhir produksi harus lebih dari nol.';
        }

        if (blank($batch->actual_output_unit)) {
            $errors['actual_output_unit'] = 'Satuan hasil akhir wajib diisi.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateBeforeSubmit(ProcessingBatch $batch): void
    {
        $errors = [];

        if ($batch->temperatureLogs->isEmpty()) {
            $errors['temperatureLogs'] = 'Minimal satu pencatatan suhu wajib tersedia.';
        }

        $invalidTemperatures = $batch->temperatureLogs
            ->filter(fn ($log): bool => ! $log->is_within_limit && blank($log->corrective_action));

        if ($invalidTemperatures->isNotEmpty()) {
            $errors['temperatureLogs'] = 'Suhu di luar batas wajib memiliki tindakan koreksi.';
        }

        if ($batch->documentations->isEmpty()) {
            $errors['documentations'] = 'Minimal satu foto dokumentasi wajib tersedia.';
        }

        if (! $batch->handover) {
            $errors['handover'] = 'Data serah-terima ke Pemorsian belum tersedia.';
        }

        $blockingDeviation = $batch->deviations->first(function ($deviation): bool {
            return in_array($deviation->severity, [
                ProcessingDeviationSeverity::High,
                ProcessingDeviationSeverity::Critical,
            ], true) && $deviation->status !== ProcessingDeviationStatus::Resolved;
        });

        if ($blockingDeviation) {
            $errors['deviations'] = 'Penyimpangan tinggi atau kritis harus diselesaikan sebelum laporan diajukan.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function writeHistory(
        ProcessingBatch $batch,
        User $actor,
        string $action,
        ?string $fromState,
        ?string $toState,
        ?string $notes = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
    ): void {
        $batch->histories()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $batch->fresh([
                'materialUsages',
                'temperatureLogs',
                'steps',
                'documentations',
                'deviations',
                'handover',
            ])->toArray(),
        ]);
    }
}
