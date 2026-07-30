<?php

namespace App\Services\Mobile;

use App\Models\MobileTask;
use App\Models\SecurityShift;

class DueMobileTaskNotifier
{
    public function __construct(
        private readonly MobileTaskService $tasks,
        private readonly MobilePushService $push,
    ) {}

    public function run(): array
    {
        SecurityShift::query()->active()->with('reports')->chunkById(100, function ($shifts): void {
            foreach ($shifts as $shift) {
                $this->tasks->syncSecurityShiftTasks($shift);
            }
        });

        $leadMinutes = max(0, (int) config('mobile.notifications.reminder_lead_minutes', 15));
        $reminders = 0;
        $overdue = 0;

        MobileTask::query()->pending()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addMinutes($leadMinutes))
            ->chunkById(100, function ($tasks) use (&$reminders): void {
                foreach ($tasks as $task) {
                    $notification = $this->push->notifyTask(
                        $task,
                        'task_due_soon',
                        'Laporan keamanan segera jatuh tempo',
                        "{$task->title} perlu dibuat sebelum {$task->due_at->format('H:i')}.",
                    );
                    if ($notification->delivery_status === 'sent') {
                        $task->update(['reminder_sent_at' => now()]);
                        $reminders++;
                    }
                }
            });

        MobileTask::query()->pending()
            ->whereNull('overdue_sent_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->chunkById(100, function ($tasks) use (&$overdue): void {
                foreach ($tasks as $task) {
                    $notification = $this->push->notifyTask(
                        $task,
                        'task_overdue',
                        'Laporan keamanan belum dibuat',
                        "{$task->title} sudah melewati waktu {$task->due_at->format('H:i')}. Segera lengkapi laporan.",
                    );
                    if ($notification->delivery_status === 'sent') {
                        $task->update(['overdue_sent_at' => now()]);
                        $overdue++;
                    }
                }
            });

        return compact('reminders', 'overdue');
    }
}
