<?php

namespace App\Data;

use Carbon\Carbon;

final readonly class ResolvedAttendanceSchedule
{
    public function __construct(
        public string $workDate,
        public int $divisionId,
        public string $divisionName,
        public int $scheduleId,
        public string $shiftName,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public int $toleranceMinutes,
    ) {}

    public function snapshot(?Carbon $checkIn = null): array
    {
        $late = $checkIn ? max(0, (int) floor($this->startsAt->copy()->addMinutes($this->toleranceMinutes)->diffInSeconds($checkIn, false) / 60)) : 0;

        return [
            'division_id' => $this->divisionId,
            'division_name_snapshot' => $this->divisionName,
            'attendance_work_schedule_id' => $this->scheduleId,
            'shift_name_snapshot' => $this->shiftName,
            'scheduled_check_in_at' => $this->startsAt,
            'scheduled_check_out_at' => $this->endsAt,
            'late_tolerance_minutes_snapshot' => $this->toleranceMinutes,
            'late_minutes' => $late,
            'punctuality_status' => $checkIn ? ($late > 0 ? 'late' : 'on_time') : null,
        ];
    }
}
