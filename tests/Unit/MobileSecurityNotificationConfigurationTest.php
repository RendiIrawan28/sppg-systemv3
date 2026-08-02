<?php

use App\Enums\SecurityShiftStatus;
use App\Models\SecurityReport;
use App\Models\SecurityShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

test('security shift creates four three-hour reporting periods', function (): void {
    expect(SecurityShift::DURATION_HOURS)->toBe(12)
        ->and(SecurityShift::REPORT_INTERVAL_HOURS)->toBe(3)
        ->and(SecurityShift::EXPECTED_REPORTS)->toBe(4)
        ->and(SecurityShift::REPORT_GRACE_MINUTES)->toBe(15);
});

test('security reporting skips a missed period instead of allowing stacked catch-up reports', function (): void {
    Carbon::setTestNow('2026-08-01 14:30:00');
    $shift = new SecurityShift([
        'started_at' => '2026-08-01 08:00:00',
        'scheduled_end_at' => '2026-08-01 20:00:00',
        'status' => SecurityShiftStatus::Active,
        'reports_expected' => 4,
    ]);
    $shift->setRelation('reports', new Collection);

    expect($shift->eligibleReportSequence())->toBe(2)
        ->and($shift->reportSequenceDueAt())->toBe(2)
        ->and($shift->missedReportSequences())->toBe([1]);

    $shift->setRelation('reports', new Collection([
        new SecurityReport(['sequence_number' => 2]),
    ]));

    expect($shift->reportSequenceDueAt())->toBeNull()
        ->and($shift->next_report_sequence)->toBe(3)
        ->and($shift->next_report_due_at->format('H:i'))->toBe('17:00');

    Carbon::setTestNow();
});

test('security shift expires after the final report grace period and marks missing periods', function (): void {
    Carbon::setTestNow('2026-08-01 20:16:00');
    $shift = new SecurityShift([
        'started_at' => '2026-08-01 08:00:00',
        'scheduled_end_at' => '2026-08-01 20:00:00',
        'status' => SecurityShiftStatus::Active,
        'reports_expected' => 4,
    ]);
    $shift->setRelation('reports', new Collection([
        new SecurityReport(['sequence_number' => 2]),
    ]));

    expect($shift->shouldExpire())->toBeTrue()
        ->and($shift->reportSequenceDueAt())->toBeNull()
        ->and($shift->missedReportSequences())->toBe([1, 3, 4]);

    Carbon::setTestNow();
});

test('security report history uses the shared documentation modal', function (): void {
    $view = file_get_contents(resource_path('views/livewire/v3/security/index.blade.php'));

    expect($view)
        ->toContain('<x-v3.documentation-button')
        ->toContain('Lihat foto kondisi')
        ->toContain('tidak dilaporkan');
});

test('firebase permission errors are converted to a safe message for employees', function (): void {
    $service = file_get_contents(app_path('Services/Mobile/MobilePushService.php'));

    expect($service)
        ->toContain("str_contains(\$message, 'cloudmessaging.messages.create')")
        ->toContain('Layanan notifikasi belum memiliki izin mengirim pesan. Hubungi administrator sistem.')
        ->not->toContain('$errors[] = $exception->getMessage()');
});

test('notification diagnostics are hidden from the employee task interface', function (): void {
    $notifications = file_get_contents(app_path('Http/Controllers/Api/MobileNotificationController.php'));
    $tasks = file_get_contents(app_path('Http/Controllers/Api/MobileTaskController.php'));
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/TaskScreens.kt'));

    expect($notifications)->toContain("where('notification_type', '!=', 'fcm_test')")
        ->and($tasks)->toContain("where('task_type', '!=', 'security_test_reminder')")
        ->and($screen)->not->toContain('Kirim notifikasi uji')
        ->and($screen)->not->toContain('Status push notification');
});

test('mobile notification defaults are safe before firebase is configured', function (): void {
    expect(config('mobile.firebase.enabled'))->toBeFalse()
        ->and(config('mobile.notifications.reminder_lead_minutes'))->toBe(15);
});

test('dedicated mobile task notification and security routes are registered', function (): void {
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri())->all();

    expect($uris)
        ->toContain('api/mobile/device-tokens')
        ->toContain('api/mobile/tasks')
        ->toContain('api/mobile/notifications')
        ->toContain('api/mobile/security/overview')
        ->toContain('api/mobile/security/shifts')
        ->toContain('api/mobile/security/shifts/{shift}/reports');
});
