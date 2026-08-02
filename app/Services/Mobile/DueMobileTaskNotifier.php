<?php

namespace App\Services\Mobile;

use App\Models\MobileTask;
use App\Models\SecurityShift;
use App\Services\SecurityMonitoringService;

class DueMobileTaskNotifier
{
    public function __construct(
        private readonly MobileTaskService $tasks,
        private readonly MobilePushService $push,
        private readonly SecurityMonitoringService $security,
    ) {}

    public function run(): array
    {
        $this->security->expireOverdueShifts();

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
                    [$title, $body] = $this->copyFor($task, overdue: false);
                    $notification = $this->push->notifyTask($task, 'task_due_soon', $title, $body);
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
                    [$title, $body] = $this->copyFor($task, overdue: true);
                    $notification = $this->push->notifyTask($task, 'task_overdue', $title, $body);
                    if ($notification->delivery_status === 'sent') {
                        $task->update(['overdue_sent_at' => now()]);
                        $overdue++;
                    }
                }
            });

        return compact('reminders', 'overdue');
    }

    /** @return array{0: string, 1: string} */
    private function copyFor(MobileTask $task, bool $overdue): array
    {
        $securityTask = str_starts_with($task->task_type, 'security_');
        $time = $task->due_at?->format('H:i') ?? '-';

        if ($overdue) {
            return [
                $securityTask ? 'Laporan keamanan belum dibuat' : 'Tugas belum diselesaikan',
                "{$task->title} sudah melewati waktu {$time}. Segera lengkapi pekerjaan.",
            ];
        }

        return [
            $securityTask ? 'Laporan keamanan segera jatuh tempo' : 'Tugas segera jatuh tempo',
            "{$task->title} perlu diselesaikan sebelum {$time}.",
        ];
    }
}
