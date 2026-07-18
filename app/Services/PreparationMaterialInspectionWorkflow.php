<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationMaterialInspection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationMaterialInspectionWorkflow
{
    public function submit(
        PreparationMaterialInspection $inspection,
        User $actor,
        ?string $notes = null,
    ): PreparationMaterialInspection {
        abort_unless($actor->can('preparation.update'), 403);

        return DB::transaction(function () use ($inspection, $actor, $notes): PreparationMaterialInspection {
            $inspection = PreparationMaterialInspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->getKey());

            if (! $inspection->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak dapat diajukan pada status saat ini.',
                ]);
            }

            $this->validateBeforeSubmit($inspection);

            $fromStatus = $inspection->status->value;

            $inspection->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                inspection: $inspection,
                actor: $actor,
                action: 'submitted',
                fromStatus: $fromStatus,
                toStatus: OperationalReportStatus::Submitted->value,
                notes: $notes,
            );

            return $inspection->refresh();
        });
    }

    public function verify(
        PreparationMaterialInspection $inspection,
        User $actor,
        ?string $notes = null,
    ): PreparationMaterialInspection {
        abort_unless($actor->can('preparation.approve'), 403);

        return DB::transaction(function () use ($inspection, $actor, $notes): PreparationMaterialInspection {
            $inspection = PreparationMaterialInspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->getKey());

            if (! app(OperationalReportApprovalService::class)->isReviewable($inspection->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $previousStatus = $inspection->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($inspection->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);

            $inspection->update([
                'status' => $nextStatus,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                inspection: $inspection,
                actor: $actor,
                action: $action,
                fromStatus: $previousStatus,
                toStatus: $nextStatus->value,
                notes: $notes,
            );

            return $inspection->refresh();
        });
    }

    public function requestRevision(
        PreparationMaterialInspection $inspection,
        User $actor,
        string $notes,
    ): PreparationMaterialInspection {
        abort_unless($actor->can('preparation.approve'), 403);

        return DB::transaction(function () use ($inspection, $actor, $notes): PreparationMaterialInspection {
            $inspection = PreparationMaterialInspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->getKey());

            if (! app(OperationalReportApprovalService::class)->isReviewable($inspection->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak sedang menunggu verifikasi.',
                ]);
            }

            $inspection->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                inspection: $inspection,
                actor: $actor,
                action: 'revision_requested',
                fromStatus: OperationalReportStatus::Submitted->value,
                toStatus: OperationalReportStatus::RevisionRequired->value,
                notes: $notes,
            );

            return $inspection->refresh();
        });
    }

    private function validateBeforeSubmit(PreparationMaterialInspection $inspection): void
    {
        $errors = [];

        if ((float) $inspection->quantity <= 0) {
            $errors['quantity'] = 'Banyaknya bahan harus lebih dari nol.';
        }

        if (blank($inspection->material_name)) {
            $errors['material_name'] = 'Nama bahan wajib diisi.';
        }

        if (blank($inspection->unit_name)) {
            $errors['unit_name'] = 'Satuan wajib diisi.';
        }

        if (! $inspection->photo_path && ! $inspection->legacy_photo_url) {
            $errors['photo_path'] = 'Foto pemeriksaan bahan wajib tersedia sebelum laporan diajukan.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function writeHistory(
        PreparationMaterialInspection $inspection,
        User $actor,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?string $notes,
    ): void {
        $inspection->histories()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $inspection->fresh()->toArray(),
        ]);
    }
}
