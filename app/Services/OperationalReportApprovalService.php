<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OperationalReportApprovalService
{
    public function nextApprovedStatus(OperationalReportStatus|string|null $status, User $actor): OperationalReportStatus
    {
        $status = $status instanceof OperationalReportStatus
            ? $status
            : OperationalReportStatus::tryFrom((string) $status);

        $this->assertCanReviewStage($status, $actor);

        if ($status === OperationalReportStatus::Submitted) {
            return OperationalReportStatus::DivisionApproved;
        }

        if ($status === OperationalReportStatus::DivisionApproved) {
            return OperationalReportStatus::Verified;
        }

        throw ValidationException::withMessages([
            'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
        ]);
    }

    public function assertCanReviewStage(OperationalReportStatus|string|null $status, User $actor): void
    {
        $status = $status instanceof OperationalReportStatus
            ? $status
            : OperationalReportStatus::tryFrom((string) $status);

        if (! $this->isReviewable($status)) {
            throw ValidationException::withMessages([
                'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
            ]);
        }

        if ($status === OperationalReportStatus::Submitted && $this->isHeadSppg($actor)) {
            throw ValidationException::withMessages([
                'status' => 'Laporan harus diperiksa Kepala Divisi terlebih dahulu sebelum ditangani Kepala SPPG.',
            ]);
        }

        if ($status === OperationalReportStatus::DivisionApproved && ! $this->isHeadSppg($actor)) {
            throw ValidationException::withMessages([
                'status' => 'Hanya Kepala SPPG yang dapat menangani laporan setelah persetujuan Kepala Divisi.',
            ]);
        }
    }

    public function reviewActionName(OperationalReportStatus $nextStatus): string
    {
        return $nextStatus === OperationalReportStatus::DivisionApproved
            ? 'division_approved'
            : 'head_approved';
    }

    public function isReviewable(OperationalReportStatus|string|null $status): bool
    {
        $status = $status instanceof OperationalReportStatus
            ? $status
            : OperationalReportStatus::tryFrom((string) $status);

        return in_array($status, [
            OperationalReportStatus::Submitted,
            OperationalReportStatus::DivisionApproved,
        ], true);
    }

    public function isHeadSppg(User $actor): bool
    {
        return method_exists($actor, 'hasRole')
            && $actor->hasRole(UserRole::KepalaSppg->value);
    }
}
