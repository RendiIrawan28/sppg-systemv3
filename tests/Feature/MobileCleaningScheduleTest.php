<?php

use App\Enums\UserRole;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-08-24 09:00:00')));

function mobileCleaningActor(): User
{
    $role = Role::findOrCreate(UserRole::PetugasKebersihan->value, 'web');
    foreach (['cleaning.view', 'cleaning.update', 'cleaning.submit'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::query()->create([
        'name' => 'Petugas Kebersihan Mobile',
        'email' => 'petugas-kebersihan-mobile@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function mobileCleaningArea(SppgUnit $unit, string $code, bool $autoSchedule = true): CleaningArea
{
    return CleaningArea::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => $code,
        'name' => 'Area '.$code,
        'category' => 'production',
        'template_type' => 'production',
        'location' => 'Area uji',
        'frequency' => 'daily',
        'auto_schedule' => $autoSchedule,
        'scheduled_time' => '07:00:00',
        'is_active' => true,
    ]);
}

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-CLN-MOB',
        'name' => 'SPPG Kebersihan Mobile',
        'slug' => 'sppg-kebersihan-mobile',
        'is_active' => true,
    ]);
    $this->cleaningActor = mobileCleaningActor();
    Sanctum::actingAs($this->cleaningActor, ['mobile']);
});

it('prepares todays cleaning sessions when the mobile module list is opened', function (): void {
    $scheduledArea = mobileCleaningArea($this->unit, 'AUTO');
    $manualArea = mobileCleaningArea($this->unit, 'MANUAL', false);

    $this->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonFragment([
            'slug' => 'kebersihan',
            'today_count' => 1,
        ]);

    $session = CleaningSession::query()
        ->where('cleaning_area_id', $scheduledArea->id)
        ->whereDate('scheduled_date', today())
        ->firstOrFail();

    expect($session->checklistItems()->count())->toBeGreaterThan(0)
        ->and(CleaningSession::query()->where('cleaning_area_id', $manualArea->id)->exists())->toBeFalse();

    $this->getJson('/api/mobile/operational-modules')->assertOk();

    expect(CleaningSession::query()
        ->where('cleaning_area_id', $scheduledArea->id)
        ->whereDate('scheduled_date', today())
        ->count())->toBe(1);
});

it('prepares todays cleaning sessions when the cleaning records are opened directly', function (): void {
    $area = mobileCleaningArea($this->unit, 'DIRECT');

    $this->getJson('/api/mobile/operational-modules/kebersihan/records')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $session = CleaningSession::query()
        ->where('cleaning_area_id', $area->id)
        ->whereDate('scheduled_date', today())
        ->firstOrFail();

    $detail = $this->getJson("/api/mobile/operational-modules/kebersihan/records/{$session->id}")
        ->assertOk();
    expect(collect($detail->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('start');

    $this->postJson("/api/mobile/operational-modules/kebersihan/records/{$session->id}/actions/start", [
        'fields' => ['started_at' => now()->format('Y-m-d H:i:s')],
    ])->assertOk();

    expect($session->refresh()->state->value)->toBe('in_progress');
});
