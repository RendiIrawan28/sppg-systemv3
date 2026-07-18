<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Models\User;
use App\Models\WasteHandoverReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WasteHandoverWorkflow
{
    public function submit(
        WasteHandoverReport $report,
        User $actor,
        ?string $notes = null,
    ): WasteHandoverReport {
        abort_unless($actor->can('preparation.update'), 403);

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($report->getKey());

            if (! $report->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Berita acara tidak dapat diajukan pada status saat ini.',
                ]);
            }

            $this->validateBeforeSubmit($report);
            $fromStatus = $report->status->value;

            $report->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                report: $report,
                actor: $actor,
                action: 'submitted',
                fromStatus: $fromStatus,
                toStatus: OperationalReportStatus::Submitted->value,
                notes: $notes,
            );

            return $report->refresh();
        });
    }

    public function verify(
        WasteHandoverReport $report,
        User $actor,
        ?string $notes = null,
    ): WasteHandoverReport {
        abort_unless($actor->can('preparation.approve'), 403);

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()
                ->lockForUpdate()
                ->findOrFail($report->getKey());

            if (! app(OperationalReportApprovalService::class)->isReviewable($report->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $previousStatus = $report->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($report->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);

            $report->update([
                'status' => $nextStatus,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                report: $report,
                actor: $actor,
                action: $action,
                fromStatus: $previousStatus,
                toStatus: $nextStatus->value,
                notes: $notes,
            );

            return $report->refresh();
        });
    }

    public function requestRevision(
        WasteHandoverReport $report,
        User $actor,
        string $notes,
    ): WasteHandoverReport {
        abort_unless($actor->can('preparation.approve'), 403);

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()
                ->lockForUpdate()
                ->findOrFail($report->getKey());

            if (! app(OperationalReportApprovalService::class)->isReviewable($report->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Berita acara tidak sedang menunggu verifikasi.',
                ]);
            }

            $report->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                report: $report,
                actor: $actor,
                action: 'revision_requested',
                fromStatus: OperationalReportStatus::Submitted->value,
                toStatus: OperationalReportStatus::RevisionRequired->value,
                notes: $notes,
            );

            return $report->refresh();
        });
    }

    private function validateBeforeSubmit(WasteHandoverReport $report): void
    {
        $errors = [];

        if (blank($report->first_party_name)) {
            $errors['first_party_name'] = 'Nama pihak pertama wajib diisi.';
        }

        if (blank($report->first_party_position)) {
            $errors['first_party_position'] = 'Jabatan pihak pertama wajib diisi.';
        }

        if (blank($report->first_party_address)) {
            $errors['first_party_address'] = 'Alamat pihak pertama wajib diisi.';
        }

        if (blank($report->second_party_name)) {
            $errors['second_party_name'] = 'Nama pihak kedua wajib diisi.';
        }

        if (blank($report->second_party_position)) {
            $errors['second_party_position'] = 'Jabatan pihak kedua wajib diisi.';
        }

        if (blank($report->second_party_address)) {
            $errors['second_party_address'] = 'Alamat pihak kedua wajib diisi.';
        }

        if ($report->items->isEmpty()) {
            $errors['items'] = 'Minimal satu item limbah wajib tersedia.';
        }

        foreach ($report->items as $index => $item) {
            $number = $index + 1;

            if (blank($item->waste_type)) {
                $errors["items.{$index}.waste_type"] = "Jenis limbah item {$number} wajib diisi.";
            }

            if ((float) $item->weight_kg <= 0) {
                $errors["items.{$index}.weight_kg"] = "Berat limbah item {$number} harus lebih dari nol.";
            }

            if (! $item->photo_path && ! $item->legacy_photo_url) {
                $errors["items.{$index}.photo_path"] = "Foto item limbah {$number} wajib tersedia.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function writeHistory(
        WasteHandoverReport $report,
        User $actor,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?string $notes,
    ): void {
        $report->histories()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $report->fresh()->load('items')->toArray(),
        ]);
    }
}
