<?php

use App\Livewire\V3\Attendance\Index;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionHistory;
use App\Models\AttendanceTap;
use App\Models\SppgUnit;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\IsolatedAttendanceDatabase;

uses(IsolatedAttendanceDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-RESET', 'name' => 'SPPG Reset', 'slug' => 'sppg-reset', 'is_active' => true,
    ]);
    $this->superAdmin = User::query()->create([
        'name' => 'Super Admin', 'email' => 'super-reset@example.test', 'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->regularAdmin = User::query()->create([
        'name' => 'Admin Biasa', 'email' => 'admin-reset@example.test', 'password' => 'password', 'is_active' => true,
    ]);
    $this->volunteer = User::query()->create([
        'name' => 'Relawan', 'email' => 'relawan-reset@example.test', 'password' => 'password', 'is_active' => true,
    ]);
    $this->regularAdmin->givePermissionTo(Permission::findOrCreate('attendance.view', 'web'));
    $this->device = AttendanceDevice::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'name' => 'RFID Test',
        'code' => 'RFID-RESET',
        'secret_hash' => hash('sha256', 'secret'),
        'is_active' => true,
    ]);
});

it('lets only a super admin reset attendance for the selected date while preserving taps', function (): void {
    $session = AttendanceSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'user_id' => $this->volunteer->id,
        'work_date' => '2026-08-03',
        'check_in_at' => '2026-08-03 07:00:00',
        'check_in_device_id' => $this->device->id,
        'source' => 'rfid',
        'status' => 'present',
    ]);
    $otherDate = AttendanceSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'user_id' => $this->volunteer->id,
        'work_date' => '2026-08-02',
        'check_in_at' => '2026-08-02 07:00:00',
        'check_in_device_id' => $this->device->id,
        'source' => 'rfid',
        'status' => 'present',
    ]);
    AttendanceTap::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'attendance_device_id' => $this->device->id,
        'user_id' => $this->volunteer->id,
        'attendance_session_id' => $session->id,
        'request_id' => 'reset-tap-1',
        'uid_snapshot' => 'ABC123',
        'action' => 'check_in',
        'result' => 'success',
        'response_message' => 'Berhasil',
        'tapped_at' => '2026-08-03 07:00:00',
        'received_at' => '2026-08-03 07:00:00',
        'is_offline' => false,
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(Index::class)
        ->set('filterDate', '2026-08-03')
        ->set('resetReason', 'Data uji perangkat')
        ->set('resetConfirmation', 'RESET')
        ->call('resetAttendance')
        ->assertHasNoErrors();

    expect(AttendanceSession::query()->find($session->id))->toBeNull()
        ->and(AttendanceSession::withTrashed()->find($session->id)?->deleted_by)->toBe($this->superAdmin->id)
        ->and(AttendanceSession::withTrashed()->find($session->id)?->deletion_reason)->toBe('Data uji perangkat')
        ->and(AttendanceSession::query()->find($otherDate->id))->not->toBeNull()
        ->and(AttendanceTap::query()->where('request_id', 'reset-tap-1')->exists())->toBeTrue();
});

it('rejects reset attempts from a non super admin', function (): void {
    Livewire::actingAs($this->regularAdmin)
        ->test(Index::class)
        ->set('filterDate', '2026-08-03')
        ->set('resetReason', 'Tidak berhak')
        ->set('resetConfirmation', 'RESET')
        ->call('resetAttendance')
        ->assertForbidden();
});

it('lets a super admin delete only the selected incorrect attendance while retaining RFID taps and audit', function (): void {
    $incorrect = AttendanceSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'user_id' => $this->volunteer->id,
        'work_date' => '2026-08-03',
        'check_in_at' => '2026-08-03 07:00:00',
        'source' => 'manual',
        'status' => 'present',
    ]);
    $valid = AttendanceSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'user_id' => $this->volunteer->id,
        'work_date' => '2026-08-03',
        'check_in_at' => '2026-08-03 07:05:00',
        'check_in_device_id' => $this->device->id,
        'source' => 'rfid',
        'status' => 'present',
    ]);
    AttendanceTap::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'attendance_device_id' => $this->device->id,
        'user_id' => $this->volunteer->id,
        'attendance_session_id' => $incorrect->id,
        'request_id' => 'delete-tap-1',
        'uid_snapshot' => 'ABC123',
        'action' => 'check_in',
        'result' => 'success',
        'response_message' => 'Berhasil',
        'tapped_at' => '2026-08-03 07:00:00',
        'received_at' => '2026-08-03 07:00:00',
        'is_offline' => false,
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(Index::class)
        ->set('filterDate', '2026-08-03')
        ->call('openDeleteSession', $incorrect->id)
        ->set('deleteReason', 'Presensi manual ganda')
        ->set('deleteConfirmation', 'HAPUS')
        ->call('deleteAttendanceSession')
        ->assertHasNoErrors();

    expect(AttendanceSession::query()->find($incorrect->id))->toBeNull()
        ->and(AttendanceSession::withTrashed()->find($incorrect->id)?->deleted_by)->toBe($this->superAdmin->id)
        ->and(AttendanceSession::withTrashed()->find($incorrect->id)?->deletion_reason)->toBe('Presensi manual ganda')
        ->and(AttendanceSession::query()->find($valid->id))->not->toBeNull()
        ->and(AttendanceSessionHistory::query()->where('attendance_session_id', $incorrect->id)->where('action', 'deleted')->where('actor_id', $this->superAdmin->id)->exists())->toBeTrue()
        ->and(AttendanceTap::query()->where('request_id', 'delete-tap-1')->where('attendance_session_id', $incorrect->id)->exists())->toBeTrue();
});

it('requires a reason and confirmation before deleting one attendance record', function (): void {
    $session = AttendanceSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'user_id' => $this->volunteer->id,
        'work_date' => '2026-08-03',
        'source' => 'manual',
        'status' => 'absent',
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(Index::class)
        ->set('filterDate', '2026-08-03')
        ->call('openDeleteSession', $session->id)
        ->call('deleteAttendanceSession')
        ->assertHasErrors(['deleteReason', 'deleteConfirmation']);

    expect(AttendanceSession::query()->find($session->id))->not->toBeNull();
});

it('rejects deleting one attendance record from a non super admin', function (): void {
    Livewire::actingAs($this->regularAdmin)
        ->test(Index::class)
        ->call('openDeleteSession', 1)
        ->assertForbidden();

    Livewire::actingAs($this->regularAdmin)
        ->test(Index::class)
        ->set('deleteSessionId', 1)
        ->set('deleteReason', 'Tidak berhak')
        ->set('deleteConfirmation', 'HAPUS')
        ->call('deleteAttendanceSession')
        ->assertForbidden();
});
