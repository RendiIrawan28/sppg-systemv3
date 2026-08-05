<?php

namespace App\Console\Commands;

use App\Services\VolunteerAttendanceService;
use Illuminate\Console\Command;

class AutoCheckOutAttendance extends Command
{
    protected $signature = 'attendance:auto-check-out';

    protected $description = 'Menutup otomatis sesi presensi yang sudah mencapai 14 jam kerja';

    public function handle(VolunteerAttendanceService $attendance): int
    {
        $total = $attendance->autoCheckOutOverdue();

        $this->info("{$total} sesi presensi ditutup otomatis.");

        return self::SUCCESS;
    }
}
