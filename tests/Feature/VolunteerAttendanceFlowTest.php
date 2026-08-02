<?php

use App\Models\AttendanceDevice;
use App\Models\AttendanceRegistrationSession;
use App\Models\AttendanceSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\VolunteerAttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create(['code' => 'SPPG-TEST', 'name' => 'SPPG Test', 'slug' => 'sppg-test', 'is_active' => true]);
    $this->user = User::query()->create(['employee_number' => 'A1B2C3D4', 'name' => 'Relawan Test', 'email' => 'relawan@example.test', 'password' => 'password', 'is_active' => true]);
    $this->deviceKey = 'secret-device-key';
    $this->device = AttendanceDevice::query()->create(['sppg_unit_id' => $this->unit->id, 'name' => 'RFID Utama', 'code' => 'RFID-TEST', 'secret_hash' => hash('sha256', $this->deviceKey), 'is_active' => true]);
});

it('records check in, ignores duplicate tap, and records check out with volunteer name', function (): void {
    $service = app(VolunteerAttendanceService::class);
    Carbon::setTestNow('2026-08-02 04:00:00');
    $checkIn = $service->recordTap($this->device, 'a1 b2-c3d4', 'tap-1');
    Carbon::setTestNow('2026-08-02 04:00:30');
    $duplicate = $service->recordTap($this->device, 'A1B2C3D4', 'tap-2');
    Carbon::setTestNow('2026-08-02 10:00:00');
    $checkOut = $service->recordTap($this->device, 'A1B2C3D4', 'tap-3');

    expect($checkIn)->toMatchArray(['action' => 'check_in', 'pegawai' => 'Relawan Test'])
        ->and($duplicate['action'])->toBe('duplicate_tap')
        ->and($checkOut)->toMatchArray(['action' => 'check_out', 'pegawai' => 'Relawan Test'])
        ->and(AttendanceSession::query()->first()->work_date->toDateString())->toBe('2026-08-02')
        ->and(AttendanceSession::query()->first()->check_out_at->format('H:i'))->toBe('10:00');
});

it('requires six hours after check out before another check in', function (): void {
    $service = app(VolunteerAttendanceService::class);
    Carbon::setTestNow('2026-08-02 04:00:00');
    $service->recordTap($this->device, 'A1B2C3D4', 'tap-1');
    Carbon::setTestNow('2026-08-02 10:00:00');
    $service->recordTap($this->device, 'A1B2C3D4', 'tap-2');
    Carbon::setTestNow('2026-08-02 12:00:00');
    $blocked = $service->recordTap($this->device, 'A1B2C3D4', 'tap-3');
    Carbon::setTestNow('2026-08-02 16:00:00');
    $newSession = $service->recordTap($this->device, 'A1B2C3D4', 'tap-4');

    expect($blocked['action'])->toBe('wait_6_hours')
        ->and($blocked['remaining_minutes'])->toBe(240)
        ->and($newSession['action'])->toBe('check_in')
        ->and(AttendanceSession::query()->count())->toBe(2);
});

it('keeps a cross-midnight session on the check-in date', function (): void {
    $service = app(VolunteerAttendanceService::class);
    Carbon::setTestNow('2026-08-02 22:00:00');
    $service->recordTap($this->device, 'A1B2C3D4', 'tap-1');
    Carbon::setTestNow('2026-08-03 02:00:00');
    $service->recordTap($this->device, 'A1B2C3D4', 'tap-2');

    $session = AttendanceSession::query()->first();
    expect($session->work_date->toDateString())->toBe('2026-08-02')
        ->and($session->check_out_at->toDateString())->toBe('2026-08-03');
});

it('rejects requests without valid device credentials', function (): void {
    $this->postJson('/api/iot/attendance/tap', ['uid_kartu' => 'A1B2C3D4', 'request_id' => 'tap-api'])
        ->assertUnauthorized()
        ->assertJsonPath('action', 'unauthorized');
});

it('returns the volunteer name through the secured device api', function (): void {
    $this->withHeaders([
        'X-Device-Code' => $this->device->code,
        'X-Device-Key' => $this->deviceKey,
        'X-Firmware-Version' => '1.0.0',
    ])->postJson('/api/iot/attendance/tap', [
        'uid_kartu' => 'A1B2C3D4',
        'request_id' => 'tap-api-valid',
    ])->assertOk()
        ->assertJsonPath('action', 'check_in')
        ->assertJsonPath('pegawai', 'Relawan Test');
});

it('registers a scanned card to the selected user during the two minute window', function (): void {
    $target = User::query()->create(['name' => 'Relawan Baru', 'email' => 'baru@example.test', 'password' => 'password', 'is_active' => true]);
    AttendanceRegistrationSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'attendance_device_id' => $this->device->id,
        'user_id' => $target->id,
        'initiated_by' => $this->user->id,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(2),
    ]);

    $this->withHeaders(['X-Device-Code' => $this->device->code, 'X-Device-Key' => $this->deviceKey])
        ->postJson('/api/iot/attendance/tap', ['uid_kartu' => 'FF001122', 'request_id' => 'register-api'])
        ->assertOk()
        ->assertJsonPath('action', 'register_card')
        ->assertJsonPath('pegawai', 'Relawan Baru');

    expect($target->refresh()->employee_number)->toBe('FF001122');
});

it('renders the attendance workspace for an authorized user', function (): void {
    $permission = Permission::findOrCreate('attendance.view', 'web');
    $this->user->givePermissionTo($permission);

    $this->actingAs($this->user)
        ->get('/v3/presensi-relawan')
        ->assertOk()
        ->assertSee('Kehadiran relawan SPPG')
        ->assertSee('Kehadiran per tanggal masuk');
});

it('exports attendance reports as pdf and excel for authorized users', function (): void {
    $permission = Permission::findOrCreate('attendance.export', 'web');
    $this->user->givePermissionTo($permission);
    $query = ['date_from' => '2026-08-01', 'date_to' => '2026-08-02'];

    $this->actingAs($this->user)->get(route('v3.attendance.pdf', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->user)->get(route('v3.attendance.xlsx', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

afterEach(function (): void {
    Carbon::setTestNow();
});
