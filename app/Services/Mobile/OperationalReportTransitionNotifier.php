<?php

namespace App\Services\Mobile;

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Sends one notification per report group and stage, including all original submitters. */
class OperationalReportTransitionNotifier
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    /** @param Collection<int, Model> $records */
    public function submitted(
        Collection $records,
        string $module,
        string $moduleLabel,
        string $summary,
        string $permission,
        string $moduleSlug,
        ?string $divisionCode = null,
        bool $openList = false,
    ): void {
        $reference = $records->first();
        if (! $reference) {
            return;
        }

        $this->notifications->notifyPermissionAfterCommit(
            unitId: (int) $reference->getAttribute('sppg_unit_id'),
            permission: $permission,
            type: $module.'_report_submitted',
            title: 'Laporan Menunggu Pemeriksaan',
            message: "{$summary} telah diajukan.",
            priority: 'important',
            module: $module,
            referenceType: $module.'_report',
            referenceId: $reference->getKey(),
            moduleSlug: $moduleSlug,
            moduleLabel: $moduleLabel,
            eventVersion: $this->eventVersion($reference, 'submitted'),
            divisionCode: $divisionCode,
            payload: $openList ? ['record_id' => ''] : [],
        );
    }

    /** @param Collection<int, Model> $records */
    public function reviewed(
        Collection $records,
        OperationalReportStatus $status,
        string $module,
        string $moduleLabel,
        string $summary,
        string $moduleSlug,
        bool $openList = false,
    ): void {
        $reference = $records->first();
        if (! $reference) {
            return;
        }

        if ($status === OperationalReportStatus::DivisionApproved) {
            $this->notifications->notifyRolesAfterCommit(
                unitId: (int) $reference->getAttribute('sppg_unit_id'),
                roles: [UserRole::KepalaSppg->value],
                type: $module.'_report_division_approved',
                title: 'Laporan Menunggu Persetujuan',
                message: "{$summary} telah diperiksa Kepala Divisi.",
                priority: 'important',
                module: $module,
                referenceType: $module.'_report',
                referenceId: $reference->getKey(),
                moduleSlug: $moduleSlug,
                moduleLabel: $moduleLabel,
                eventVersion: $this->eventVersion($reference, $status->value),
                payload: $openList ? ['record_id' => ''] : [],
            );

            return;
        }

        $this->approved($records, $module, $moduleLabel, $summary, $moduleSlug, $openList);
    }

    /** @param Collection<int, Model> $records */
    public function approved(Collection $records, string $module, string $moduleLabel, string $summary, string $moduleSlug, bool $openList = false): void
    {
        $this->notifySubmitters($records, $module, $moduleLabel, $summary, $moduleSlug, approved: true, openList: $openList);
    }

    /** @param Collection<int, Model> $records */
    public function revisionRequired(Collection $records, string $module, string $moduleLabel, string $summary, string $moduleSlug, bool $openList = false): void
    {
        $this->notifySubmitters($records, $module, $moduleLabel, $summary, $moduleSlug, approved: false, openList: $openList);
    }

    /** @param Collection<int, Model> $records */
    private function notifySubmitters(
        Collection $records,
        string $module,
        string $moduleLabel,
        string $summary,
        string $moduleSlug,
        bool $approved,
        bool $openList,
    ): void {
        $reference = $records->first();
        $submitterIds = $records->map(fn (Model $record) => $record->getAttribute('submitted_by')
            ?: $record->getAttribute('petugas_id')
            ?: $record->getAttribute('created_by'))
            ->filter()
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        if (! $reference || $submitterIds === []) {
            return;
        }

        $stage = $approved ? 'approved' : 'revision_required';
        $this->notifications->notifyUsersAfterCommit(
            unitId: (int) $reference->getAttribute('sppg_unit_id'),
            userIds: $submitterIds,
            type: $module.'_report_'.$stage,
            title: $approved ? 'Laporan Disetujui' : 'Laporan Perlu Diperbaiki',
            message: $approved
                ? "{$summary} telah disetujui Kepala SPPG."
                : "{$summary} dikembalikan untuk diperbaiki.",
            priority: $approved ? 'info' : 'important',
            module: $module,
            referenceType: $module.'_report',
            referenceId: $reference->getKey(),
            moduleSlug: $moduleSlug,
            moduleLabel: $moduleLabel,
            eventVersion: $this->eventVersion($reference, $stage),
            payload: $openList ? ['record_id' => ''] : [],
        );
    }

    private function eventVersion(Model $record, string $stage): string
    {
        return $stage.'-'.($record->updated_at?->format('YmdHisu') ?? now()->format('YmdHisu'));
    }
}
