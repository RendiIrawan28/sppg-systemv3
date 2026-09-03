<?php

namespace App\Services;

use App\Data\ResolvedAttendanceSchedule;
use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\Division;
use App\Models\User;
use Carbon\Carbon;

class AttendanceWorkScheduleResolver
{
    public function primaryDivision(User $user, int $unitId): ?Division
    {
        return $user->divisions()->wherePivot('sppg_unit_id', $unitId)->wherePivot('is_active', true)
            ->where('divisions.is_active', true)->orderByPivot('is_primary', 'desc')
            ->orderBy('divisions.sort_order')->orderBy('divisions.id')->first();
    }

    public function resolveForUserAndWorkDate(User $user, int $unitId, Carbon $workDate): ?ResolvedAttendanceSchedule
    {
        if (! $user->is_active) {
            return null;
        }
        $day = $workDate->copy()->setTimezone(config('app.timezone'))->startOfDay();
        $division = $this->primaryDivision($user, $unitId);
        if (! $division) {
            return null;
        }
        $assignment = AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $unitId)
            ->where('user_id', $user->id)->where('is_active', true)->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->with('workSchedule.division')->orderByDesc('effective_from')->orderByDesc('id')->first();

        if ($assignment) {
            $schedule = $assignment->workSchedule;
            // An assigned off-day must never fall back to the division's default.
            if (! $schedule || (int) $schedule->sppg_unit_id !== $unitId || ! $schedule->division?->is_active || ! $schedule->appliesToDate($day)
                || ! $user->divisions()->wherePivot('sppg_unit_id', $unitId)->wherePivot('is_active', true)->where('divisions.id', $schedule->division_id)->exists()) {
                return null;
            }
        } else {
            $schedule = AttendanceWorkSchedule::query()->where('sppg_unit_id', $unitId)->where('division_id', $division->id)
                ->where('is_default', true)->where('is_active', true)->with('division')->orderBy('id')->get()
                ->first(fn ($schedule) => $schedule->appliesToDate($day));
        }
        if (! $schedule) {
            return null;
        }
        $start = $day->copy()->setTimeFromTimeString($schedule->start_time);
        $end = $day->copy()->setTimeFromTimeString($schedule->end_time);
        if ($schedule->spansMidnight()) {
            $end->addDay();
        }

        return new ResolvedAttendanceSchedule($day->toDateString(), $schedule->division_id, $schedule->division->name, $schedule->id, $schedule->name, $start, $end, $schedule->late_tolerance_minutes);
    }

    public function resolveForTap(User $user, int $unitId, Carbon $eventAt): ?ResolvedAttendanceSchedule
    {
        $eventAt = $eventAt->copy()->setTimezone(config('app.timezone'));
        $previous = $this->resolveForUserAndWorkDate($user, $unitId, $eventAt->copy()->subDay());
        if ($previous && $eventAt->gt($previous->startsAt) && $eventAt->lte($previous->endsAt)) {
            return $previous;
        }

        return $this->resolveForUserAndWorkDate($user, $unitId, $eventAt);
    }

    public function unscheduledSnapshot(User $user, int $unitId): array
    {
        $division = $this->primaryDivision($user, $unitId);

        return ['division_id' => $division?->id, 'division_name_snapshot' => $division?->name, 'attendance_work_schedule_id' => null, 'shift_name_snapshot' => null, 'scheduled_check_in_at' => null, 'scheduled_check_out_at' => null, 'late_tolerance_minutes_snapshot' => null, 'late_minutes' => 0, 'punctuality_status' => null];
    }
}
