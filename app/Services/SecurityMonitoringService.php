<?php

namespace App\Services;

use App\Enums\SecurityShiftStatus;
use App\Models\SecurityReport;
use App\Models\SecurityShift;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\Mobile\MobileTaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SecurityMonitoringService
{
    public function startShift(SppgUnit $unit, User $actor, ?Carbon $startedAt = null): SecurityShift
    {
        abort_unless($actor->can('security.create'), 403);

        $this->expireOverdueShifts($unit->getKey(), $actor->getKey(), $startedAt ?? now());

        $shift = DB::transaction(function () use ($unit, $actor, $startedAt): SecurityShift {
            $activeShift = SecurityShift::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('officer_id', $actor->getKey())
                ->active()
                ->lockForUpdate()
                ->first();

            if ($activeShift) {
                throw ValidationException::withMessages([
                    'shift' => 'Anda masih memiliki shift keamanan yang aktif.',
                ]);
            }

            $startedAt ??= now();

            return SecurityShift::query()->create([
                'sppg_unit_id' => $unit->getKey(),
                'officer_id' => $actor->getKey(),
                'officer_name_snapshot' => $actor->name,
                'started_at' => $startedAt,
                'scheduled_end_at' => $startedAt->copy()->addHours(SecurityShift::DURATION_HOURS),
                'status' => SecurityShiftStatus::Active,
                'reports_expected' => SecurityShift::EXPECTED_REPORTS,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });

        app(MobileTaskService::class)->syncSecurityShiftTasks($shift);

        return $shift;
    }

    public function submitReport(
        SecurityShift $shift,
        User $actor,
        array $data,
        ?Carbon $reportedAt = null,
    ): SecurityReport {
        abort_unless($actor->can('security.create'), 403);

        $reportedAt ??= now();
        $this->expireOverdueShifts($shift->sppg_unit_id, $shift->officer_id, $reportedAt);

        $report = DB::transaction(function () use ($shift, $actor, $data, $reportedAt): SecurityReport {
            $shift = SecurityShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_super_admin || (int) $shift->officer_id === (int) $actor->getKey(), 403);

            if ($shift->status !== SecurityShiftStatus::Active) {
                throw ValidationException::withMessages(['shift' => 'Shift keamanan 12 jam ini sudah berakhir.']);
            }

            $sequence = $shift->reportSequenceDueAt($reportedAt);
            if (! $sequence) {
                throw ValidationException::withMessages([
                    'shift' => 'Laporan untuk periode ini belum waktunya atau sudah pernah dibuat.',
                ]);
            }

            $validated = validator($data, [
                'situation' => ['required', Rule::in(['safe', 'attention', 'emergency'])],
                'gate_secure' => ['required', 'boolean'],
                'perimeter_secure' => ['required', 'boolean'],
                'access_activity' => ['nullable', 'string', 'max:5000'],
                'visitor_activity' => ['nullable', 'string', 'max:5000'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'photo_path' => ['required', 'string', 'max:255'],
            ])->validate();

            $report = $shift->reports()->create([
                ...$validated,
                'sppg_unit_id' => $shift->sppg_unit_id,
                'sequence_number' => $sequence,
                'due_at' => $shift->started_at->copy()->addHours(SecurityShift::REPORT_INTERVAL_HOURS * $sequence),
                'reported_at' => $reportedAt,
                'created_by' => $actor->getKey(),
            ]);

            if ($sequence >= $shift->reports_expected) {
                $shift->update([
                    'status' => SecurityShiftStatus::Completed,
                    'completed_at' => $reportedAt,
                    'updated_by' => $actor->getKey(),
                ]);
            }

            return $report->refresh();
        });

        app(MobileTaskService::class)->completeSecurityReportTask($report);

        return $report;
    }

    public function expireOverdueShifts(?int $unitId = null, ?int $officerId = null, ?Carbon $at = null): int
    {
        $at ??= now();
        $query = SecurityShift::query()
            ->active()
            ->where('scheduled_end_at', '<=', $at->copy()->subMinutes(SecurityShift::REPORT_GRACE_MINUTES));

        if ($unitId !== null) {
            $query->where('sppg_unit_id', $unitId);
        }
        if ($officerId !== null) {
            $query->where('officer_id', $officerId);
        }

        $expired = 0;
        $query->with('reports')->each(function (SecurityShift $shift) use (&$expired): void {
            DB::transaction(fn () => $this->expireShift($shift));
            $expired++;
        });

        return $expired;
    }

    private function expireShift(SecurityShift $shift): void
    {
        $shift->update([
            'status' => SecurityShiftStatus::Expired,
            'completed_at' => $shift->scheduled_end_at,
            'updated_by' => $shift->officer_id,
        ]);

        app(MobileTaskService::class)->syncSecurityShiftTasks($shift->refresh()->load('reports'));
    }
}
