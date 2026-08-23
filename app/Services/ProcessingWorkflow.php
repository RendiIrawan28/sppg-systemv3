<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Models\ProcessingBatch;
use App\Models\PreparationOutputWithdrawal;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingWorkflow
{
    public function start(ProcessingBatch $batch, User $actor): ProcessingBatch
    {
        abort_unless($actor->can('processing.update'), 403);
        $batch->loadMissing('fieldDistributionPlan');
        $serviceDate = $batch->service_date ?: $batch->fieldDistributionPlan?->distribution_date ?: $batch->production_date;
        try {
            app(MenuServiceCalendarService::class)->assertOperationalDate((int) $batch->sppg_unit_id, $serviceDate, 'Pengolahan untuk pelayanan');
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['service_date' => $exception->getMessage()]);
        }

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

    public function cancel(ProcessingBatch $batch, User $actor, string $reason): ProcessingBatch
    {
        abort_unless($actor->can('processing.update'), 403);

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan produksi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($batch, $actor, $reason): ProcessingBatch {
            $batch = $this->lockedBatch($batch);
            if (! in_array($batch->state, [ProcessingBatchState::Planned, ProcessingBatchState::InProgress], true)
                || ! $batch->isReportEditable()) {
                throw ValidationException::withMessages([
                    'state' => 'Produksi tidak dapat dibatalkan pada status saat ini.',
                ]);
            }

            if (! $this->canCancel($batch)) {
                throw ValidationException::withMessages([
                    'state' => 'Produksi sudah memiliki pengambilan bahan. Selesaikan atau retur bahan terlebih dahulu.',
                ]);
            }

            $fromState = $batch->state->value;
            $reason = trim($reason);
            $existingNotes = trim((string) $batch->notes);
            $batch->update([
                'state' => ProcessingBatchState::Cancelled,
                'completed_at' => now(),
                'notes' => trim($existingNotes."\nProduksi dibatalkan: {$reason}"),
                'updated_by' => $actor->getKey(),
            ]);
            $this->writeHistory(
                $batch,
                $actor,
                'production_cancelled',
                $fromState,
                ProcessingBatchState::Cancelled->value,
                $reason,
            );

            return $batch->refresh();
        });
    }

    public function canCancel(ProcessingBatch $batch): bool
    {
        if (! in_array($batch->state, [ProcessingBatchState::Planned, ProcessingBatchState::InProgress], true)
            || ! $batch->isReportEditable()) {
            return false;
        }

        return ! $batch->materialUsages()->exists()
            && ! $batch->preparationOutputWithdrawals()->exists()
            && ! WarehouseWithdrawal::query()
                ->where('division_code', 'pengolahan')
                ->where('reference_type', 'processing_batch')
                ->where('reference_id', $batch->getKey())
                ->whereHas('items')
                ->exists();
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
            ->with([
                'materialUsages.returns',
                'preparationOutputWithdrawals.output',
                'temperatureLogs',
                'documentations',
            ])
            ->lockForUpdate()
            ->findOrFail($batch->getKey());
    }

    private function validateBeforeComplete(ProcessingBatch $batch): void
    {
        $errors = [];
        if (! $this->hasActualMaterialInput($batch)) {
            $errors['materialUsages'] = 'Minimal satu bahan baku aktual wajib dicatat sebelum Pengolahan diselesaikan.';
        }
        $finalTemperatures = $batch->temperatureLogs
            ->where('checkpoint', ProcessingTemperatureCheckpoint::Final);
        if ($finalTemperatures->isEmpty()) {
            $errors['temperatureLogs'] = 'Minimal satu suhu makanan setelah matang wajib dicatat.';
        }

        if ($finalTemperatures->contains(fn ($temperature): bool => blank($temperature->photo_path))) {
            $errors['temperatureDocumentations'] = 'Setiap makanan matang wajib memiliki foto pengukuran suhu.';
        }
        $finishedOutputs = $batch->documentations
            ->where('documentation_type', 'finished_output')
            ->values();

        if ($finishedOutputs->isEmpty()) {
            $errors['outputDocumentation'] = 'Minimal satu data berat atau jumlah makanan jadi wajib dicatat.';
        } else {
            if ($finishedOutputs->contains(
                fn ($documentation): bool => (float) $documentation->output_quantity <= 0
            )) {
                $errors['outputQuantity'] = 'Setiap data makanan jadi wajib memiliki berat atau jumlah lebih dari nol.';
            }

            if ($finishedOutputs->contains(
                fn ($documentation): bool => blank($documentation->output_unit)
            )) {
                $errors['outputUnit'] = 'Setiap data makanan jadi wajib memiliki satuan.';
            }

            if ($finishedOutputs->contains(
                fn ($documentation): bool => blank($documentation->photo_path)
            )) {
                $errors['outputDocumentation'] = 'Setiap data berat atau jumlah makanan jadi wajib memiliki foto.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasActualMaterialInput(ProcessingBatch $batch): bool
    {
        $hasMaterialUsage = $batch->materialUsages->contains(
            fn ($usage): bool => filled($usage->material_name)
                && (float) $usage->quantity > 0
                && filled($usage->unit_name),
        );
        if ($hasMaterialUsage) {
            return true;
        }

        return $batch->preparationOutputWithdrawals->contains(
            fn ($withdrawal): bool => in_array($withdrawal->status, [
                PreparationOutputWithdrawal::WAITING,
                PreparationOutputWithdrawal::VERIFIED,
            ], true) && (float) ($withdrawal->verified_quantity ?: $withdrawal->requested_quantity) > 0,
        );
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
                'preparationOutputWithdrawals.output',
                'temperatureLogs',
                'documentations',
            ])->toArray(),
        ]);
    }
}
