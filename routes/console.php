<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bersihkan token aplikasi yang sudah kedaluwarsa lebih dari 24 jam.
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('mobile:send-task-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();
