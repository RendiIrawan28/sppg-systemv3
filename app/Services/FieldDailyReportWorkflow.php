<?php

namespace App\Services;

use App\Enums\FieldDailyReportStatus;
use App\Models\ContainerCollectionTask;
use App\Models\FieldDailyReport;
use App\Models\User;
use App\Support\V3\SystemUnit;
use DomainException;
use Illuminate\Support\Facades\DB;

class FieldDailyReportWorkflow
{
    public function submissionIssues(FieldDailyReport $report): array
    {
        $report->load(['divisions', 'incidents']);
        $issues = [];

        if (! $report->generated_at) {
            $issues[] = 'Data operasional belum ditarik. Jalankan Buat/Perbarui Rekap terlebih dahulu.';
        }

        if (! $report->field_distribution_plan_id) {
            $issues[] = 'Rencana distribusi pada tanggal laporan tidak ditemukan.';
        }

        $pendingContainers = ContainerCollectionTask::query()
            ->where('sppg_unit_id', $report->sppg_unit_id)
            ->whereDate('delivery_date', $report->report_date)
            ->where('remaining_containers', '>', 0)
            ->sum('remaining_containers');

        if ($pendingContainers > 0) {
            $issues[] = "Masih ada {$pendingContainers} ompreng yang belum selesai diambil.";
        }

        foreach ($report->divisions as $division) {
            if ($division->total_records === 0) {
                $issues[] = "{$division->division_name}: belum memiliki laporan.";
            } elseif ($division->completion_status !== 'verified') {
                $issues[] = "{$division->division_name}: laporan belum seluruhnya disetujui.";
            }
        }

        if ($report->divisions->count() < 6) {
            $issues[] = 'Ringkasan enam divisi belum lengkap.';
        }

        if ($report->delivered_portions <= 0) {
            $issues[] = 'Belum ada porsi yang tercatat terkirim.';
        }

        if (blank($report->operational_summary)) {
            $issues[] = 'Ringkasan operasional wajib diisi.';
        }

        if (blank($report->evaluation)) {
            $issues[] = 'Evaluasi pelaksanaan wajib diisi.';
        }

        if (blank($report->follow_up)) {
            $issues[] = 'Tindak lanjut wajib diisi.';
        }

        $blockingIncidents = $report->incidents
            ->whereIn('severity', ['high', 'critical'])
            ->whereIn('status', ['open', 'in_progress', 'pending']);

        if ($blockingIncidents->isNotEmpty()) {
            $issues[] = 'Masih ada insiden tingkat tinggi atau kritis yang belum diselesaikan.';
        }

        return array_values(array_unique($issues));
    }

    /** @param array<string, mixed> $data */
    public function update(FieldDailyReport $report, User $actor, array $data): void
    {
        $this->ensureAccess($report, $actor, 'update');
        if (! $report->isEditable()) {
            throw new DomainException('Laporan tidak berada pada status yang dapat diubah.');
        }

        $report->forceFill([
            'operational_summary' => trim((string) ($data['operational_summary'] ?? '')) ?: null,
            'obstacles' => trim((string) ($data['obstacles'] ?? '')) ?: null,
            'evaluation' => trim((string) ($data['evaluation'] ?? '')) ?: null,
            'follow_up' => trim((string) ($data['follow_up'] ?? '')) ?: null,
            'recommendations' => trim((string) ($data['recommendations'] ?? '')) ?: null,
            'prepared_by' => $report->prepared_by ?: $actor->getKey(),
            'prepared_by_name_snapshot' => $report->prepared_by_name_snapshot ?: $actor->name,
        ])->save();
    }

    public function submit(FieldDailyReport $report, User $actor, ?string $notes = null): void
    {
        $this->ensureAccess($report, $actor, 'submit');
        if (! $report->isEditable()) {
            throw new DomainException('Laporan tidak berada pada status yang dapat diajukan.');
        }

        $report = app(FieldDailyReportGenerator::class)->generate(
            (int) $report->sppg_unit_id,
            $report->report_date->toDateString(),
            $actor,
            $report,
        );

        $issues = $this->submissionIssues($report);
        if ($issues !== []) {
            throw new DomainException(implode("\n", $issues));
        }

        DB::transaction(function () use ($report, $actor, $notes): void {
            $report->forceFill([
                'status' => FieldDailyReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'review_notes' => $notes,
            ])->save();
        });
    }

    public function approve(FieldDailyReport $report, User $actor, ?string $notes = null): void
    {
        $this->ensureAccess($report, $actor, 'approve');
        if ($report->status !== FieldDailyReportStatus::Submitted) {
            throw new DomainException('Hanya laporan yang menunggu persetujuan yang dapat disetujui.');
        }
        if ((int) $report->submitted_by === (int) $actor->getKey()) {
            throw new DomainException('Pengaju tidak boleh menyetujui laporannya sendiri.');
        }

        $report->forceFill([
            'status' => FieldDailyReportStatus::Approved,
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'review_notes' => $notes,
        ])->save();
    }

    public function requestRevision(FieldDailyReport $report, User $actor, string $notes): void
    {
        $this->ensureAccess($report, $actor, 'approve');
        if ($report->status !== FieldDailyReportStatus::Submitted) {
            throw new DomainException('Hanya laporan yang menunggu persetujuan yang dapat direvisi.');
        }
        if (blank($notes)) {
            throw new DomainException('Catatan revisi wajib diisi.');
        }

        $report->forceFill([
            'status' => FieldDailyReportStatus::RevisionRequired,
            'approved_by' => null,
            'approved_at' => null,
            'review_notes' => trim($notes),
        ])->save();
    }

    private function ensureAccess(FieldDailyReport $report, User $actor, string $action): void
    {
        if (! $actor->can("field_daily_reports.{$action}")) {
            throw new DomainException('Anda tidak memiliki izin untuk menjalankan proses laporan harian ini.');
        }
        if (! app(SystemUnit::class)->owns($report)) {
            throw new DomainException('Laporan bukan milik Unit SPPG aktif.');
        }
    }
}
