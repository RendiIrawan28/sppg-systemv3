<?php

namespace App\Console\Commands;

use App\Services\Mobile\DueMobileTaskNotifier;
use Illuminate\Console\Command;

class SendDueMobileTaskNotifications extends Command
{
    protected $signature = 'mobile:send-task-reminders';

    protected $description = 'Mengirim pengingat tugas mobile yang mendekati atau melewati batas waktu.';

    public function handle(DueMobileTaskNotifier $notifier): int
    {
        $result = $notifier->run();
        $this->info("Pengingat: {$result['reminders']}; terlambat: {$result['overdue']}.");

        return self::SUCCESS;
    }
}
