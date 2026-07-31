<?php

namespace App\Console\Commands;

use App\Models\MobileTask;
use App\Models\User;
use App\Services\Mobile\DueMobileTaskNotifier;
use App\Support\V3\SystemUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTestMobileReminder extends Command
{
    protected $signature = 'mobile:create-test-reminder
        {user : ID, email, atau nomor pegawai pengguna}
        {--minutes=2 : Jarak jatuh tempo dalam menit}
        {--send : Langsung jalankan pengirim pengingat}';

    protected $description = 'Membuat tugas pengingat uji untuk memeriksa FCM dan Laravel Scheduler.';

    public function handle(SystemUnit $systemUnit, DueMobileTaskNotifier $notifier): int
    {
        $lookup = trim((string) $this->argument('user'));
        $user = User::query()
            ->where(function ($query) use ($lookup): void {
                if (ctype_digit($lookup)) {
                    $query->orWhereKey((int) $lookup);
                }
                $query->orWhere('email', $lookup)
                    ->orWhere('employee_number', $lookup);
            })
            ->first();

        if (! $user) {
            $this->error('Pengguna tidak ditemukan.');

            return self::FAILURE;
        }
        if (! $user->is_active) {
            $this->error('Pengguna ditemukan, tetapi status akunnya tidak aktif.');

            return self::FAILURE;
        }

        $unitId = $systemUnit->id();
        if (! $unitId) {
            $this->error('Unit SPPG aktif tidak ditemukan.');

            return self::FAILURE;
        }

        $minutes = min(60, max(1, (int) $this->option('minutes')));
        $dueAt = now()->addMinutes($minutes);
        $task = MobileTask::query()->create([
            'sppg_unit_id' => $unitId,
            'user_id' => $user->getKey(),
            'task_type' => 'security_test_reminder',
            'reference_type' => null,
            'reference_id' => null,
            'sequence_number' => null,
            'title' => 'Uji pengingat laporan keamanan',
            'description' => 'Tugas ini dibuat dari command diagnostik FCM dan dapat dihapus setelah pengujian.',
            'priority' => 'high',
            'channel' => 'sppg_report_reminders',
            'screen' => 'security',
            'payload' => ['test' => '1'],
            'due_at' => $dueAt,
            'status' => 'pending',
            'dedupe_key' => hash('sha256', 'security-test-reminder:'.Str::uuid()),
        ]);

        $this->info("Tugas #{$task->getKey()} dibuat untuk {$user->name}.");
        $this->line("Jatuh tempo: {$dueAt->format('d-m-Y H:i:s')}");

        if ($this->option('send')) {
            $result = $notifier->run();
            $this->info("Pengingat terkirim: {$result['reminders']}; terlambat: {$result['overdue']}.");
        } else {
            $this->comment('Jalankan php artisan mobile:send-task-reminders untuk mengirim pengingat.');
        }

        return self::SUCCESS;
    }
}
