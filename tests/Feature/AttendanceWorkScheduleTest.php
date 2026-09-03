<?php

use App\Livewire\V3\Attendance\WorkSchedules;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionHistory;
use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\Division;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\AttendanceAbsenceService;
use App\Services\AttendanceScheduleManager;
use App\Services\AttendanceWorkScheduleResolver;
use App\Services\VolunteerAttendanceService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\IsolatedAttendanceDatabase;

uses(IsolatedAttendanceDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-03 08:00:00');
    $this->unit = SppgUnit::create(['name' => 'SPPG Uji Shift', 'code' => 'SHIFT', 'slug' => 'shift', 'is_active' => true]);
    $this->division = Division::create(['name' => 'Pengolahan', 'code' => 'PENGOLAHAN', 'is_active' => true, 'sort_order' => 2]);
    $this->user = User::create(['name' => 'Pegawai Uji', 'email' => 'shift@example.test', 'password' => 'test', 'employee_number' => '00AB12', 'is_active' => true]);
    $this->user->divisions()->attach($this->division, ['sppg_unit_id' => $this->unit->id, 'is_primary' => true, 'is_active' => true]);
    $this->device = AttendanceDevice::create(['sppg_unit_id' => $this->unit->id, 'name' => 'Alat Uji', 'code' => 'UJI', 'secret_hash' => hash('sha256', 'test'), 'is_active' => true]);
    $this->form = ['division_id' => $this->division->id, 'name' => 'Shift Sore', 'start_time' => '16:00', 'end_time' => '23:00', 'late_tolerance_minutes' => 10, 'work_days' => [1, 2, 3, 4, 5, 6], 'is_default' => true, 'is_active' => true, 'effective_from' => '2026-09-03', 'effective_until' => null];
    $this->shift = app(AttendanceScheduleManager::class)->saveSchedule($this->unit->id, $this->form, $this->user);
});

afterEach(fn () => Carbon::setTestNow());

it('counts lateness in full minutes after tolerance', function (string $time, int $minutes, string $status) {
    Carbon::setTestNow('2026-09-03 '.$time);
    $result = app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'first');
    $session = AttendanceSession::sole();
    expect($result)->toMatchArray(['action' => 'check_in', 'pegawai' => 'Pegawai Uji'])
        ->and($session->late_minutes)->toBe($minutes)->and($session->punctuality_status)->toBe($status)
        ->and($session->division_name_snapshot)->toBe('Pengolahan')->and($session->shift_name_snapshot)->toBe('Shift Sore');
})->with([['15:50:00', 0, 'on_time'], ['16:10:00', 0, 'on_time'], ['16:10:30', 0, 'on_time'], ['16:11:00', 1, 'late'], ['16:15:59', 5, 'late']]);

it('keeps midnight taps on the previous scheduled work date', function () {
    $this->shift->update(['start_time' => '22:00', 'end_time' => '06:00']);
    Carbon::setTestNow('2026-09-04 00:10:00');
    app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'night');
    $session = AttendanceSession::sole();
    expect($session->work_date->toDateString())->toBe('2026-09-03')->and($session->late_minutes)->toBe(120)
        ->and($session->scheduled_check_out_at->format('Y-m-d H:i'))->toBe('2026-09-04 06:00');
});

it('does not fall back to the default on an assigned off-day', function () {
    $special = AttendanceWorkSchedule::create([...$this->form, 'sppg_unit_id' => $this->unit->id, 'name' => 'Khusus', 'is_default' => false, 'work_days' => [1, 2, 3, 4, 5]]);
    app(AttendanceScheduleManager::class)->saveAssignment($this->unit->id, ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $special->id, 'effective_from' => '2026-09-03', 'effective_until' => null, 'is_active' => true, 'notes' => null], $this->user);
    expect(app(AttendanceWorkScheduleResolver::class)->resolveForUserAndWorkDate($this->user, $this->unit->id, Carbon::parse('2026-09-05')))->toBeNull();
    Carbon::setTestNow('2026-09-05 16:30:00');
    app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'offday');
    expect(AttendanceSession::sole()->punctuality_status)->toBeNull();
});

it('creates absence only after scheduled end and is idempotent', function () {
    $service = app(AttendanceAbsenceService::class);
    expect($service->markAbsentForCompletedSchedules(Carbon::parse('2026-09-03 22:59:59')))->toBe(0)
        ->and($service->markAbsentForCompletedSchedules(Carbon::parse('2026-09-03 23:00:00')))->toBe(1)
        ->and($service->markAbsentForCompletedSchedules(Carbon::parse('2026-09-03 23:01:00')))->toBe(0);
    expect(AttendanceSession::sole()->source)->toBe('system_absence')->and(AttendanceSession::sole()->check_in_at)->toBeNull();
});

it('does not create absence before schedule effective date or on off-days', function () {
    expect(app(AttendanceAbsenceService::class)->markAbsentForCompletedSchedules(Carbon::parse('2026-09-02 23:30:00')))->toBe(0);
    $this->shift->update(['work_days' => [1, 2, 3]]);
    expect(app(AttendanceAbsenceService::class)->markAbsentForCompletedSchedules(Carbon::parse('2026-09-03 23:30:00')))->toBe(0);
});

it('reconciles offline check-in with automatic absence without creating a duplicate', function () {
    Carbon::setTestNow('2026-09-04 08:00:00');
    app(AttendanceAbsenceService::class)->markAbsentForCompletedSchedules();
    $id = AttendanceSession::sole()->id;
    $service = app(VolunteerAttendanceService::class);
    $result = $service->recordTap($this->device, '00AB12', 'offline', Carbon::parse('2026-09-03T09:15:00Z'), true);
    expect($result['action'])->toBe('check_in')->and(AttendanceSession::count())->toBe(1)
        ->and(AttendanceSession::sole()->id)->toBe($id)->and(AttendanceSession::sole()->late_minutes)->toBe(5)
        ->and(AttendanceSession::sole()->source)->toBe('rfid_offline')
        ->and(AttendanceSessionHistory::sole()->action)->toBe('auto_absence_reconciled');
    expect($service->recordTap($this->device, '00AB12', 'offline', Carbon::parse('2026-09-03T09:15:00Z'), true))->toBe($result);
});

it('never overwrites manual nonattendance with RFID', function (string $status) {
    AttendanceSession::create(['sppg_unit_id' => $this->unit->id, 'user_id' => $this->user->id, 'work_date' => '2026-09-03', 'status' => $status, 'source' => 'manual']);
    Carbon::setTestNow('2026-09-03 16:00:00');
    $result = app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'conflict');
    expect($result['action'])->toBe('attendance_status_conflict')->and(AttendanceSession::sole()->status)->toBe($status);
})->with(['permission', 'sick', 'absent']);

it('preserves four-hour minimum and fourteen-hour auto checkout regardless of shift end', function () {
    $this->shift->update(['start_time' => '08:00', 'end_time' => '10:00']);
    $service = app(VolunteerAttendanceService::class);
    $service->recordTap($this->device, '00AB12', 'in');
    Carbon::setTestNow('2026-09-03 10:00:00');
    expect($service->recordTap($this->device, '00AB12', 'early')['action'])->toBe('wait_4_hours')
        ->and($service->autoCheckOutOverdue())->toBe(0);
    Carbon::setTestNow('2026-09-03 22:00:00');
    expect($service->autoCheckOutOverdue())->toBe(1)->and(AttendanceSession::sole()->check_out_at->format('H:i'))->toBe('22:00');
});

it('does not mark reentry within the same work date late', function () {
    $this->shift->update(['start_time' => '04:00', 'end_time' => '23:00']);
    $service = app(VolunteerAttendanceService::class);
    foreach (['04:00', '08:00', '14:00'] as $index => $time) {
        Carbon::setTestNow('2026-09-03 '.$time);
        $service->recordTap($this->device, '00AB12', 'tap-'.$index);
    }
    expect(AttendanceSession::count())->toBe(2)->and(AttendanceSession::latest('id')->first()->late_minutes)->toBe(0)
        ->and(AttendanceSession::latest('id')->first()->punctuality_status)->toBeNull();
});

it('rejects overlapping default days but allows disjoint days and date ranges', function () {
    $manager = app(AttendanceScheduleManager::class);
    expect(fn () => $manager->saveSchedule($this->unit->id, [...$this->form, 'name' => 'Bentrok'], $this->user))->toThrow(ValidationException::class);
    expect($manager->saveSchedule($this->unit->id, [...$this->form, 'work_days' => [7]], $this->user))->toBeInstanceOf(AttendanceWorkSchedule::class);
    $this->shift->update(['effective_until' => '2026-09-05']);
    expect($manager->saveSchedule($this->unit->id, [...$this->form, 'effective_from' => '2026-09-06'], $this->user))->toBeInstanceOf(AttendanceWorkSchedule::class);
});

it('rejects backdated new schedules and overlapping user assignments', function () {
    $manager = app(AttendanceScheduleManager::class);
    expect(fn () => $manager->saveSchedule($this->unit->id, [...$this->form, 'effective_from' => '2026-09-02'], $this->user))->toThrow(ValidationException::class);
    $data = ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $this->shift->id, 'effective_from' => '2026-09-03', 'effective_until' => null, 'is_active' => true];
    $manager->saveAssignment($this->unit->id, $data, $this->user);
    expect(fn () => $manager->saveAssignment($this->unit->id, $data, $this->user))->toThrow(ValidationException::class);
});

it('keeps attendance snapshots unchanged when the schedule or division is edited', function () {
    Carbon::setTestNow('2026-09-03 16:15:00');
    app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'snapshot');
    $session = AttendanceSession::sole();
    $this->shift->update(['start_time' => '18:00', 'name' => 'Baru']);
    $this->division->update(['name' => 'Divisi Baru']);
    expect($session->refresh()->division_name_snapshot)->toBe('Pengolahan')->and($session->shift_name_snapshot)->toBe('Shift Sore')->and($session->late_minutes)->toBe(5);
    app(VolunteerAttendanceService::class)->saveManual($this->unit->id, $this->user, ['work_date' => '2026-09-03', 'check_in_at' => Carbon::parse('2026-09-03 16:16'), 'check_out_at' => null, 'status' => 'present'], $this->user, 'Koreksi waktu', $session);
    expect($session->refresh()->late_minutes)->toBe(6)->and($session->shift_name_snapshot)->toBe('Shift Sore');
});

it('resolves manual midnight check-in and stores an audited schedule snapshot', function () {
    $this->shift->update(['start_time' => '22:00', 'end_time' => '06:00']);
    $session = app(VolunteerAttendanceService::class)->saveManual($this->unit->id, $this->user, ['work_date' => '2026-09-03', 'check_in_at' => Carbon::parse('2026-09-03 00:10'), 'check_out_at' => Carbon::parse('2026-09-03 06:00'), 'status' => 'present'], $this->user, 'Kartu tertinggal');
    expect($session->check_in_at->format('Y-m-d H:i'))->toBe('2026-09-04 00:10')->and($session->late_minutes)->toBe(120)
        ->and(AttendanceSessionHistory::sole()->after_data['shift_name_snapshot'])->toBe('Shift Sore');
});

it('does not recreate reset sessions or mark inactive employees absent', function () {
    Carbon::setTestNow('2026-09-03 23:30:00');
    $service = app(AttendanceAbsenceService::class);
    expect($service->markAbsentForCompletedSchedules())->toBe(1);
    AttendanceSession::sole()->delete();
    expect($service->markAbsentForCompletedSchedules())->toBe(0);
    $this->user->update(['is_active' => false]);
    Carbon::setTestNow('2026-09-04 23:30:00');
    expect($service->markAbsentForCompletedSchedules())->toBe(0);
});

it('revises established schedules from today without changing the previous day', function () {
    $manager = app(AttendanceScheduleManager::class);
    $manager->saveAssignment($this->unit->id, ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $this->shift->id, 'effective_from' => '2026-09-03', 'effective_until' => null, 'is_active' => true], $this->user);
    Carbon::setTestNow('2026-09-04 08:00:00');
    $new = $manager->saveSchedule($this->unit->id, [...$this->form, 'start_time' => '18:00'], $this->user, $this->shift->id);
    $resolver = app(AttendanceWorkScheduleResolver::class);
    expect($new->id)->not->toBe($this->shift->id)
        ->and($resolver->resolveForUserAndWorkDate($this->user, $this->unit->id, Carbon::parse('2026-09-03'))->startsAt->format('H:i'))->toBe('16:00')
        ->and($resolver->resolveForUserAndWorkDate($this->user, $this->unit->id, Carbon::parse('2026-09-04'))->startsAt->format('H:i'))->toBe('18:00')
        ->and(AttendanceWorkScheduleAssignment::count())->toBe(2);
});

it('rejects assignments outside the user division and unit', function () {
    $other = SppgUnit::create(['code' => 'OTHER', 'name' => 'Unit lain', 'is_active' => true]);
    $otherSchedule = AttendanceWorkSchedule::create([...$this->form, 'sppg_unit_id' => $other->id]);
    expect(fn () => app(AttendanceScheduleManager::class)->saveAssignment($this->unit->id, ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $otherSchedule->id, 'effective_from' => '2026-09-03', 'effective_until' => null, 'is_active' => true], $this->user))->toThrow(ValidationException::class);
    expect(app(AttendanceWorkScheduleResolver::class)->resolveForUserAndWorkDate($this->user, $other->id, Carbon::parse('2026-09-03')))->toBeNull();
});

it('preserves previous employee assignments when an override is revised', function () {
    $manager = app(AttendanceScheduleManager::class);
    $data = ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $this->shift->id, 'effective_from' => '2026-09-03', 'effective_until' => null, 'is_active' => true];
    $assignment = $manager->saveAssignment($this->unit->id, $data, $this->user);
    $special = AttendanceWorkSchedule::create([...$this->form, 'sppg_unit_id' => $this->unit->id, 'is_default' => false, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00']);
    Carbon::setTestNow('2026-09-04 08:00');
    $manager->saveAssignment($this->unit->id, [...$data, 'attendance_work_schedule_id' => $special->id], $this->user, $assignment->id);
    $resolver = app(AttendanceWorkScheduleResolver::class);
    expect($resolver->resolveForUserAndWorkDate($this->user, $this->unit->id, Carbon::parse('2026-09-03'))->shiftName)->toBe('Shift Sore')
        ->and($resolver->resolveForUserAndWorkDate($this->user, $this->unit->id, Carbon::parse('2026-09-04'))->shiftName)->toBe('Malam');
});

it('never creates a duplicate session for an out-of-order offline check-in', function () {
    Carbon::setTestNow('2026-09-03 16:30');
    app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'newer');
    $response = app(VolunteerAttendanceService::class)->recordTap($this->device, '00AB12', 'older', Carbon::parse('2026-09-03 16:15'), true);
    expect($response['action'])->toBe('attendance_status_conflict')->and(AttendanceSession::count())->toBe(1);
});

it('resolves primary division first then falls back to sort order', function () {
    $other = Division::create(['name' => 'Gudang', 'code' => 'GUDANG', 'sort_order' => 1, 'is_active' => true]);
    $this->user->divisions()->attach($other->id, ['sppg_unit_id' => $this->unit->id, 'is_active' => true, 'is_primary' => false]);
    $resolver = app(AttendanceWorkScheduleResolver::class);
    expect($resolver->primaryDivision($this->user, $this->unit->id)->id)->toBe($this->division->id);
    $this->user->divisions()->updateExistingPivot($this->division->id, ['is_primary' => false]);
    expect($resolver->primaryDivision($this->user, $this->unit->id)->id)->toBe($other->id);
});

it('restricts schedule settings to the approved roles', function (string $role, bool $allowed) {
    $this->user->assignRole(Role::findOrCreate($role, 'web'));
    $page = Livewire::actingAs($this->user)->test(WorkSchedules::class);
    $allowed ? $page->assertOk()->assertSee('Tambah jam kerja') : $page->assertForbidden();
})->with([['admin_sppg', true], ['kepala_sppg', true], ['akuntan', false], ['satpam', false]]);

it('saves schedule and assignment from the actual Livewire forms', function () {
    $this->user->update(['is_super_admin' => true]);
    $page = Livewire::actingAs($this->user)->test(WorkSchedules::class)
        ->set('form', [...$this->form, 'name' => 'Shift Khusus Malam', 'is_default' => false, 'start_time' => '22:00', 'end_time' => '06:00'])
        ->call('saveSchedule')->assertHasNoErrors()->assertSee('Shift Khusus Malam');
    $special = AttendanceWorkSchedule::where('name', 'Shift Khusus Malam')->sole();
    $page->set('assignment', ['user_id' => $this->user->id, 'attendance_work_schedule_id' => $special->id, 'effective_from' => '2026-09-03', 'effective_until' => '', 'is_active' => true, 'notes' => 'Penugasan uji'])
        ->call('saveAssignment')->assertHasNoErrors();
    expect(AttendanceWorkScheduleAssignment::sole()->attendance_work_schedule_id)->toBe($special->id);

    if (getenv('ATTENDANCE_QA_HTML')) {
        $html = $this->actingAs($this->user)->get(route('v3.attendance.work-schedules'))->assertOk()->getContent();
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<link\b[^>]*>/si', '', $html);
        $css = '';
        foreach (glob(public_path('build/assets/*.css')) as $file) {
            $css .= file_get_contents($file);
        }
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $html = preg_replace('/src="[^"]*images\/logo-bgn.png"/', 'src="data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-bgn.png'))).'"', $html);
        file_put_contents(getenv('ATTENDANCE_QA_HTML').'-light.html', $html);
        file_put_contents(getenv('ATTENDANCE_QA_HTML').'-dark.html', str_replace('<html lang="id">', '<html lang="id" class="dark">', $html));
    }
});
