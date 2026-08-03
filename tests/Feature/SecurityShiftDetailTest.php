<?php

use App\Enums\SecurityShiftStatus;
use App\Models\SecurityReport;
use App\Models\SecurityShift;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-TEST',
        'name' => 'SPPG Test',
        'slug' => 'sppg-test',
        'head_name' => 'Kepala Test',
        'is_active' => true,
    ]);
    $this->viewer = User::query()->create([
        'name' => 'Kepala SPPG',
        'email' => 'kepala@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $this->officer = User::query()->create([
        'name' => 'Satpam Satu',
        'email' => 'satpam@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $this->otherOfficer = User::query()->create([
        'name' => 'Satpam Dua',
        'email' => 'satpam2@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);

    $permission = Permission::findOrCreate('security.view', 'web');
    $this->viewer->givePermissionTo($permission);
    $role = Role::findOrCreate('satpam', 'web');
    $role->givePermissionTo($permission);
    $this->officer->assignRole($role);
    $this->otherOfficer->assignRole($role);

    $this->shift = SecurityShift::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'officer_id' => $this->officer->id,
        'officer_name_snapshot' => $this->officer->name,
        'started_at' => '2026-08-01 08:00:00',
        'scheduled_end_at' => '2026-08-01 20:00:00',
        'completed_at' => '2026-08-01 20:00:00',
        'status' => SecurityShiftStatus::Completed,
        'created_by' => $this->officer->id,
    ]);
    SecurityReport::query()->create([
        'security_shift_id' => $this->shift->id,
        'sppg_unit_id' => $this->unit->id,
        'sequence_number' => 1,
        'due_at' => '2026-08-01 11:00:00',
        'reported_at' => '2026-08-01 11:05:00',
        'situation' => 'safe',
        'gate_secure' => true,
        'perimeter_secure' => false,
        'access_activity' => 'Mobil supplier masuk',
        'visitor_activity' => 'Tamu dari sekolah',
        'notes' => 'Area belakang perlu diperiksa',
        'photo_path' => 'v3/security/reports/test.jpg',
        'created_by' => $this->officer->id,
    ]);
});

it('shows all uploaded security report details to an authorized supervisor', function (): void {
    $this->actingAs($this->viewer)
        ->get(route('v3.security.shifts.show', $this->shift))
        ->assertOk()
        ->assertSee('Rincian Shift')
        ->assertSee('Mobil supplier masuk')
        ->assertSee('Tamu dari sekolah')
        ->assertSee('Area belakang perlu diperiksa')
        ->assertSee('Lihat foto kondisi');
});

it('allows a security officer to view only their own shift', function (): void {
    $this->actingAs($this->officer)
        ->get(route('v3.security.shifts.show', $this->shift))
        ->assertOk();

    $this->actingAs($this->otherOfficer)
        ->get(route('v3.security.shifts.show', $this->shift))
        ->assertForbidden();
});

it('exports a security shift as pdf and excel', function (): void {
    $this->actingAs($this->viewer)
        ->get(route('v3.security.shifts.pdf', $this->shift))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->viewer)
        ->get(route('v3.security.shifts.xlsx', $this->shift))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
