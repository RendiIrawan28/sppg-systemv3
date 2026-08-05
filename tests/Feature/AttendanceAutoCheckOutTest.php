<?php

use App\Models\AttendanceSession;
use App\Models\SppgUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('automatically checks out an open attendance session after fourteen hours', function (): void {
    Carbon::setTestNow('2026-08-06 22:30:00');
    $unit = SppgUnit::query()->create(['code' => 'SPPG-AUTO', 'name' => 'SPPG Auto', 'slug' => 'sppg-auto', 'is_active' => true]);
    $user = User::query()->create(['name' => 'Relawan Auto', 'email' => 'auto@example.test', 'password' => 'password', 'is_active' => true]);
    $session = AttendanceSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'user_id' => $user->id,
        'work_date' => '2026-08-06',
        'check_in_at' => '2026-08-06 08:00:00',
        'source' => 'rfid',
        'status' => 'present',
    ]);

    $this->artisan('attendance:auto-check-out')
        ->expectsOutput('1 sesi presensi ditutup otomatis.')
        ->assertSuccessful();

    $session->refresh();
    expect($session->check_out_at->format('Y-m-d H:i:s'))->toBe('2026-08-06 22:00:00')
        ->and($session->check_out_source)->toBe('automatic')
        ->and($session->notes)->toContain('14 jam kerja');
});

it('does not check out a session before it reaches fourteen hours', function (): void {
    Carbon::setTestNow('2026-08-06 21:59:00');
    $unit = SppgUnit::query()->create(['code' => 'SPPG-WAIT', 'name' => 'SPPG Wait', 'slug' => 'sppg-wait', 'is_active' => true]);
    $user = User::query()->create(['name' => 'Relawan Wait', 'email' => 'wait@example.test', 'password' => 'password', 'is_active' => true]);
    $session = AttendanceSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'user_id' => $user->id,
        'work_date' => '2026-08-06',
        'check_in_at' => '2026-08-06 08:00:00',
        'source' => 'rfid',
        'status' => 'present',
    ]);

    $this->artisan('attendance:auto-check-out')
        ->expectsOutput('0 sesi presensi ditutup otomatis.')
        ->assertSuccessful();

    expect($session->refresh()->check_out_at)->toBeNull();
});

afterEach(function (): void {
    Carbon::setTestNow();
});
