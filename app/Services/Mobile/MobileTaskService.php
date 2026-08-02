<?php

namespace App\Services\Mobile;

use App\Models\MobileTask;
use App\Models\SecurityReport;
use App\Models\SecurityShift;

class MobileTaskService
{
    public function syncSecurityShiftTasks(SecurityShift $shift): void
    {
        $shift->loadMissing('reports');
        $completedSequences = $shift->reports->pluck('sequence_number')->map(fn ($value) => (int) $value);
        $missedSequences = collect($shift->missedReportSequences());

        for ($sequence = 1; $sequence <= (int) $shift->reports_expected; $sequence++) {
            $dueAt = $shift->started_at->copy()->addHours(SecurityShift::REPORT_INTERVAL_HOURS * $sequence);
            $completed = $completedSequences->contains($sequence);
            $missed = ! $completed && $missedSequences->contains($sequence);
            $dedupeKey = hash('sha256', "security-report:{$shift->getKey()}:{$sequence}");

            MobileTask::query()->updateOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'sppg_unit_id' => $shift->sppg_unit_id,
                    'user_id' => $shift->officer_id,
                    'task_type' => 'security_periodic_report',
                    'reference_type' => SecurityShift::class,
                    'reference_id' => $shift->getKey(),
                    'sequence_number' => $sequence,
                    'title' => "Laporan keamanan ke-{$sequence}",
                    'description' => 'Periksa gerbang, perimeter, aktivitas akses, dan kondisi lingkungan SPPG.',
                    'priority' => 'high',
                    'channel' => 'sppg_report_reminders',
                    'screen' => 'security',
                    'payload' => [
                        'shift_id' => (string) $shift->getKey(),
                        'sequence_number' => (string) $sequence,
                    ],
                    'due_at' => $dueAt,
                    'status' => $completed ? 'completed' : ($missed ? 'missed' : 'pending'),
                    'completed_at' => $completed
                        ? $shift->reports->firstWhere('sequence_number', $sequence)?->reported_at
                        : null,
                ],
            );
        }
    }

    public function completeSecurityReportTask(SecurityReport $report): void
    {
        MobileTask::query()
            ->where('task_type', 'security_periodic_report')
            ->where('reference_type', SecurityShift::class)
            ->where('reference_id', $report->security_shift_id)
            ->where('sequence_number', $report->sequence_number)
            ->update([
                'status' => 'completed',
                'completed_at' => $report->reported_at ?? now(),
            ]);
    }
}
