<?php

namespace App\Console\Commands;

use App\Services\AttendanceAbsenceService;
use Illuminate\Console\Command;

class MarkAttendanceAbsent extends Command
{
    protected $signature = 'attendance:mark-absent';

    protected $description = 'Mencatat ketidakhadiran untuk jadwal kerja yang telah selesai.';

    public function handle(AttendanceAbsenceService $service): int
    {
        $this->info($service->markAbsentForCompletedSchedules().' ketidakhadiran dicatat.');

        return self::SUCCESS;
    }
}
