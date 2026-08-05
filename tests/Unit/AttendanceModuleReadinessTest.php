<?php

use App\Enums\UserRole;
use App\Support\AccessControl;

it('limits attendance management to the approved roles', function (): void {
    expect(AccessControl::permissionsForRole(UserRole::KepalaSppg->value))->toContain('attendance.devices', 'attendance.correct')
        ->and(AccessControl::permissionsForRole(UserRole::AdminSppg->value))->toContain('attendance.devices', 'attendance.correct')
        ->and(AccessControl::permissionsForRole(UserRole::PengawasKeuangan->value))->toContain('attendance.correct', 'attendance.export')
        ->not->toContain('attendance.devices')
        ->and(AccessControl::permissionsForRole(UserRole::Satpam->value))->not->toContain('attendance.view');
});

it('exposes secured device endpoints and volunteer name lcd payloads', function (): void {
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(app_path('Services/VolunteerAttendanceService.php'));

    expect($routes)->toContain("Route::prefix('iot/attendance')")
        ->and($service)->toContain("'pegawai' => \$user->name")
        ->toContain('MINIMUM_WORK_HOURS = 4')
        ->toContain('REENTRY_WAIT_HOURS = 6')
        ->toContain('DUPLICATE_SECONDS = 60');
});
