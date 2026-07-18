<?php

namespace App\Services;

use App\Enums\FieldDailyReportStatus;
use App\Models\FieldDailyReport;
use App\Models\User;
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

        foreach ($report->divisions as $division) {
            if ($division->total_records === 0) {
                $issues[] = "{$division->division_name}: belum memiliki laporan.";
            } elseif ($division->completion_status !== 'verified') {
                $issues[] = "{$division->division_name}: laporan belum seluruhnya terverifikasi.";
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

    public function submit(FieldDailyReport $report, User $actor, ?string $notes = null): void
    {
        if (! $report->isEditable()) {
            throw new DomainException('Laporan tidak berada pada status yang dapat diajukan.');
        }

        $issues = $this->submissionIssues($report);

        if ($issues !== []) {
            throw new DomainException(implode("\n", $issues));
        }

        DB::transaction(function () use ($report, $actor, $notes): void {
            $report->forceFill([
                'status' => FieldDailyReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'review_notes' => $notes,
            ])->save();
        });
    }

    public function approve(FieldDailyReport $report, User $actor, ?string $notes = null): void
    {
        if ($report->status !== FieldDailyReportStatus::Submitted) {
            throw new DomainException('Hanya laporan yang menunggu persetujuan yang dapat disetujui.');
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
        if ($report->status !== FieldDailyReportStatus::Submitted) {
            throw new DomainException('Hanya laporan yang menunggu persetujuan yang dapat direvisi.');
        }

        $report->forceFill([
            'status' => FieldDailyReportStatus::RevisionRequired,
            'approved_by' => null,
            'approved_at' => null,
            'review_notes' => $notes,
        ])->save();
    }
}
