<?php

use App\Models\SecurityShift;
use Illuminate\Support\Facades\Route;

test('security shift creates four three-hour reporting periods', function (): void {
    expect(SecurityShift::DURATION_HOURS)->toBe(12)
        ->and(SecurityShift::REPORT_INTERVAL_HOURS)->toBe(3)
        ->and(SecurityShift::EXPECTED_REPORTS)->toBe(4);
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
