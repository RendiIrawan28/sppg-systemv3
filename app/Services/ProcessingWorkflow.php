<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
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
            if ($batch->materialUsages->isEmpty()) {
                throw ValidationException::withMessages([
                    'materialUsages' => 'Batch belum menerima bahan dari pengambilan Gudang.',
                ]);
            }

            $fromState = $batch->state->value;
            $batch->update([
                'state' => ProcessingBatchState::InProgress,
                'started_at' => $batch->started_at ?? now(),
                'petugas_id' => $actor->id,
                'petugas_name_snapshot' => $actor->name,
                'updated_by' => $actor->id,
            ]);
            $this->writeHistory(
                $batch,
                $actor,
                'production_started',
                $fromState,
                ProcessingBatchState::InProgress->value,
            );

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
                    'state' => 'Hanya Pengolahan yang sedang dikerjakan yang dapat diselesaikan.',
                ]);
            }

            $this->validateBeforeComplete($batch);
            $fromState = $batch->state->value;
            $batch->update([
                'state' => ProcessingBatchState::Completed,
                'completed_at' => $batch->completed_at ?? now(),
                'updated_by' => $actor->id,
            ]);
            $this->writeHistory(
                $batch,
                $actor,
                'production_completed',
                $fromState,
                ProcessingBatchState::Completed->value,
            );

            return $batch->refresh();
        });
    }

    public function submit(
        ProcessingBatch $batch,
        User $actor,
        ?string $notes = null,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.submit'), 403);

        return DB::transaction(function () use ($batch, $actor, $notes): ProcessingBatch {
            $batch = $this->lockedBatch($batch);
            if (! $batch->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengolahan harus diselesaikan sebelum laporan diajukan.',
                ]);
            }
            $this->validateBeforeComplete($batch);

            $fromStatus = $batch->status->value;
            $batch->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'division_approved_by' => null,
                'division_approved_at' => null,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->id,
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
            $approval = app(OperationalReportApprovalService::class);
            if (! $approval->isReviewable($batch->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $nextStatus = $approval->nextApprovedStatus($batch->status, $actor);
            $fromStatus = $batch->status->value;
            $updates = [
                'status' => $nextStatus,
                'review_notes' => $notes,
                'updated_by' => $actor->id,
            ];
            if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                $updates += [
                    'division_approved_by' => $actor->id,
                    'division_approved_at' => now(),
                ];
            } else {
                $updates += [
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ];
            }
            $batch->update($updates);
            $this->writeHistory(
                $batch,
                $actor,
                $approval->reviewActionName($nextStatus),
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
            app(OperationalReportApprovalService::class)->assertCanReviewStage($batch->status, $actor);
            if (blank($notes)) {
                throw ValidationException::withMessages([
                    'reviewNotes' => 'Alasan revisi wajib diisi.',
                ]);
            }

            $fromStatus = $batch->status->value;
            $batch->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => trim($notes),
                'updated_by' => $actor->id,
            ]);
            $this->writeHistory(
                $batch,
                $actor,
                'revision_requested',
                $batch->state->value,
                $batch->state->value,
                $notes,
                $fromStatus,
                OperationalReportStatus::RevisionRequired->value,
            );

            return $batch->refresh();
        });
    }

    /** @return array<int, string> */
    public function submissionIssues(ProcessingBatch $batch): array
    {
        try {
            $this->validateBeforeComplete($this->lockedBatch($batch));

            return [];
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->values()->all();
        }
    }

    private function lockedBatch(ProcessingBatch $batch): ProcessingBatch
    {
        return ProcessingBatch::query()
            ->with(['materialUsages.returns', 'temperatureLogs', 'documentations'])
            ->lockForUpdate()
            ->findOrFail($batch->getKey());
    }

    private function validateBeforeComplete(ProcessingBatch $batch): void
    {
        $errors = [];
        if ($batch->materialUsages->isEmpty()) {
            $errors['materialUsages'] = 'Minimal satu bahan dari Gudang harus tersedia.';
        }
        if ((float) $batch->actual_output_quantity <= 0) {
            $errors['actual_output_quantity'] = 'Jumlah hasil akhir harus lebih dari nol.';
        }
        if (blank($batch->actual_output_unit)) {
            $errors['actual_output_unit'] = 'Satuan hasil akhir wajib diisi.';
        }

        $finalTemperatures = $batch->temperatureLogs
            ->where('checkpoint', ProcessingTemperatureCheckpoint::Final);
        if ($finalTemperatures->isEmpty()) {
            $errors['temperatureLogs'] = 'Minimal satu suhu makanan setelah matang wajib dicatat.';
        }

        if ($finalTemperatures->contains(fn ($temperature): bool => blank($temperature->photo_path))) {
            $errors['temperatureDocumentations'] = 'Setiap makanan matang wajib memiliki foto pengukuran suhu.';
        }
        $hasFinishedOutputPhoto = $batch->documentations
            ->where('documentation_type', 'finished_output')
            ->contains(fn ($documentation): bool => filled($documentation->photo_path));
        if (! $hasFinishedOutputPhoto) {
            $errors['outputDocumentation'] = 'Foto berat atau jumlah makanan jadi wajib dilampirkan.';
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
            'actor_id' => $actor->id,
            'action' => $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $batch->fresh([
                'materialUsages.returns',
                'temperatureLogs',
                'documentations',
            ])->toArray(),
        ]);
    }
}
