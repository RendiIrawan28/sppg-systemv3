<?php

use App\Enums\SecurityShiftStatus;
use App\Models\SecurityReport;
use App\Models\SecurityShift;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');

    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-SEC-MOBILE',
        'name' => 'SPPG Keamanan Mobile',
        'slug' => 'sppg-keamanan-mobile',
        'is_active' => true,
    ]);
    $this->officer = User::query()->create([
        'name' => 'Satpam Mobile',
        'email' => 'satpam-mobile@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $permission = Permission::findOrCreate('security.view', 'web');
    $role = Role::findOrCreate('satpam', 'web');
    $role->givePermissionTo($permission);
    $this->officer->assignRole($role);

    $this->shift = SecurityShift::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'officer_id' => $this->officer->id,
        'officer_name_snapshot' => $this->officer->name,
        'started_at' => today()->setTime(7, 0),
        'scheduled_end_at' => today()->setTime(19, 0),
        'completed_at' => today()->setTime(19, 0),
        'status' => SecurityShiftStatus::Completed,
        'created_by' => $this->officer->id,
    ]);
    $path = 'mobile/keamanan/reports/report-test.jpg';
    Storage::disk('public')->put($path, 'image-contents');
    $this->report = SecurityReport::query()->create([
        'security_shift_id' => $this->shift->id,
        'sppg_unit_id' => $this->unit->id,
        'sequence_number' => 1,
        'due_at' => today()->setTime(10, 0),
        'reported_at' => today()->setTime(10, 5),
        'situation' => 'safe',
        'gate_secure' => true,
        'perimeter_secure' => true,
        'photo_path' => $path,
        'created_by' => $this->officer->id,
    ]);

    Sanctum::actingAs($this->officer, ['mobile']);
});

it('includes report photos in security history and serves them through a signed response', function (): void {
    $response = $this->getJson('/api/mobile/security/overview?date='.today()->toDateString())
        ->assertOk()
        ->assertJsonPath('data.recent_shifts.0.reports.0.id', $this->report->id);

    $photoUrl = $response->json('data.recent_shifts.0.reports.0.photo_url');
    expect($photoUrl)->toBeString()
        ->and($photoUrl)->toContain("/api/mobile/security/reports/{$this->report->id}/photo")
        ->and($photoUrl)->toContain('signature=');

    $relativeUrl = parse_url($photoUrl, PHP_URL_PATH).'?'.parse_url($photoUrl, PHP_URL_QUERY);
    $this->get($relativeUrl)
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');

    $this->get(str_replace('signature=', 'signature=invalid', $relativeUrl))
        ->assertForbidden();
});
