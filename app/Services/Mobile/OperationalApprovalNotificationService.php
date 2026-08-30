<?php

namespace App\Services\Mobile;

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;

class OperationalApprovalNotificationService
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    public function submitted(
        Model $record,
        string $module,
        string $moduleLabel,
        string $divisionCode,
        string $number,
    ): void {
        $this->notifications->notifyPermissionAfterCommit(
            unitId: (int) $record->getAttribute('sppg_unit_id'),
            permission: $module.'.approve',
            type: $module.'_report_submitted',
            title: 'Laporan Menunggu Pemeriksaan',
            message: "{$moduleLabel} {$number} telah diajukan.",
            priority: 'important',
            module: $module,
            referenceType: $module.'_report',
            referenceId: $record->getKey(),
            moduleSlug: $this->slug($module),
            moduleLabel: $moduleLabel,
            eventVersion: OperationalReportStatus::Submitted->value,
            divisionCode: $divisionCode,
        );
    }

    public function reviewed(
        Model $record,
        OperationalReportStatus $status,
        string $module,
        string $moduleLabel,
        string $number,
    ): void {
        if ($status === OperationalReportStatus::DivisionApproved) {
            $this->notifications->notifyRolesAfterCommit(
                unitId: (int) $record->getAttribute('sppg_unit_id'),
                roles: [UserRole::KepalaSppg->value],
                type: $module.'_report_division_approved',
                title: 'Laporan Menunggu Persetujuan',
                message: "{$moduleLabel} {$number} telah diperiksa Kepala Divisi.",
                priority: 'important',
                module: $module,
                referenceType: $module.'_report',
                referenceId: $record->getKey(),
                moduleSlug: $this->slug($module),
                moduleLabel: $moduleLabel,
                eventVersion: $status->value,
            );

            return;
        }

        $this->notifySubmitter($record, $module, $moduleLabel, $number, true);
    }

    public function revisionRequired(
        Model $record,
        string $module,
        string $moduleLabel,
        string $number,
    ): void {
        $this->notifySubmitter($record, $module, $moduleLabel, $number, false);
    }

    private function notifySubmitter(Model $record, string $module, string $moduleLabel, string $number, bool $approved): void
    {
        $userId = $record->getAttribute('submitted_by')
            ?: $record->getAttribute('petugas_id')
            ?: $record->getAttribute('created_by');
        if (! $userId) {
            return;
        }

        $this->notifications->notifyUsersAfterCommit(
            unitId: (int) $record->getAttribute('sppg_unit_id'),
            userIds: [(int) $userId],
            type: $module.'_report_'.($approved ? 'approved' : 'revision_required'),
            title: $approved ? 'Laporan Disetujui' : 'Laporan Perlu Diperbaiki',
            message: $approved
                ? "{$moduleLabel} {$number} telah disetujui Kepala SPPG."
                : "{$moduleLabel} {$number} dikembalikan untuk diperbaiki.",
            priority: $approved ? 'info' : 'important',
            module: $module,
            referenceType: $module.'_report',
            referenceId: $record->getKey(),
            moduleSlug: $this->slug($module),
            moduleLabel: $moduleLabel,
            eventVersion: $approved ? OperationalReportStatus::Verified->value : OperationalReportStatus::RevisionRequired->value,
        );
    }

    private function slug(string $module): string
    {
        return match ($module) {
            'preparation' => 'persiapan',
            'processing' => 'pengolahan',
            'portioning' => 'pemorsian',
            'distribution' => 'distribusi',
            default => $module,
        };
    }
}
