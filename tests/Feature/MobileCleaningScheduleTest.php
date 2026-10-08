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

it('shows only todays unfinished cleaning work as active while keeping older work in dated history', function (): void {
    $todayArea = mobileCleaningArea($this->unit, 'TODAY');
    $oldPlannedArea = mobileCleaningArea($this->unit, 'OLD-PLANNED', false);
    $oldStartedArea = mobileCleaningArea($this->unit, 'OLD-STARTED', false);

    $oldPlanned = CleaningSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'cleaning_area_id' => $oldPlannedArea->id,
        'scheduled_date' => '2026-08-23',
        'state' => 'planned',
    ]);
    $oldStarted = CleaningSession::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'cleaning_area_id' => $oldStartedArea->id,
        'scheduled_date' => '2026-08-23',
        'state' => 'in_progress',
    ]);

    $this->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonFragment(['slug' => 'kebersihan', 'today_count' => 1]);

    $todaySession = CleaningSession::query()
        ->where('cleaning_area_id', $todayArea->id)
        ->whereDate('scheduled_date', today())
        ->firstOrFail();

    $active = $this->getJson('/api/mobile/operational-modules/kebersihan/records?view=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    expect(collect($active->json('data'))->pluck('id')->all())->toBe([$todaySession->id]);

    $this->getJson('/api/mobile/operational-modules/kebersihan/records?date_from=2026-08-24&date_to=2026-08-24')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $history = $this->getJson('/api/mobile/operational-modules/kebersihan/records?date_from=2026-08-23&date_to=2026-08-23')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
    expect(collect($history->json('data'))->pluck('id')->all())
        ->toContain($oldPlanned->id, $oldStarted->id)
        ->not->toContain($todaySession->id);
});

it('exposes and downloads the same cleaning period exports as the website', function (): void {
    $this->cleaningActor->givePermissionTo(Permission::findOrCreate('cleaning.export', 'web'));
    $production = mobileCleaningArea($this->unit, 'PRODUCTION-EXPORT', false);
    $warehouse = mobileCleaningArea($this->unit, 'GUDANG-EXPORT', false);
    $warehouse->update(['template_type' => 'warehouse']);
    $unsupported = mobileCleaningArea($this->unit, 'CUSTOM-EXPORT', false);
    $unsupported->update(['template_type' => 'custom']);

    $list = $this->getJson('/api/mobile/operational-modules/kebersihan/records?date_from=2026-08-25&date_to=2026-08-25')
        ->assertOk()
        ->assertJsonPath('period_exports.can_export', true)
        ->assertJsonPath('period_exports.has_warehouses', true);

    expect(collect($list->json('period_exports.areas'))->pluck('scope')->all())
        ->toContain((string) $production->id, (string) $warehouse->id)
        ->not->toContain((string) $unsupported->id);

    $query = '?start_date=2026-08-24&end_date=2026-09-04';
    $areaPdf = $this->get("/api/mobile/operational-modules/kebersihan/period-export/{$production->id}{$query}")
        ->assertOk();
    expect($areaPdf->headers->get('content-type'))->toContain('application/pdf');

    $warehousePdf = $this->get("/api/mobile/operational-modules/kebersihan/period-export/warehouses{$query}")
        ->assertOk();
    expect($warehousePdf->headers->get('content-type'))->toContain('application/pdf');

    $this->get("/api/mobile/operational-modules/kebersihan/period-export/{$unsupported->id}{$query}")
        ->assertStatus(422);
    $this->get("/api/mobile/operational-modules/kebersihan/period-export/{$production->id}?start_date=2026-08-24&end_date=2026-10-01")
        ->assertStatus(422);

    $otherUnit = SppgUnit::query()->create([
        'code' => 'SPPG-OTHER-CLN',
        'name' => 'SPPG Lain',
        'slug' => 'sppg-lain-cleaning',
        'is_active' => true,
    ]);
    $otherArea = mobileCleaningArea($otherUnit, 'OTHER-AREA', false);
    $this->get("/api/mobile/operational-modules/kebersihan/period-export/{$otherArea->id}{$query}")
        ->assertNotFound();
});

it('rejects cleaning period export without export permission', function (): void {
    $area = mobileCleaningArea($this->unit, 'NO-EXPORT', false);
    $this->cleaningActor->roles->each(fn (Role $role) => $role->revokePermissionTo('cleaning.export'));

    $this->getJson('/api/mobile/operational-modules/kebersihan/records?date_from=2026-08-25&date_to=2026-08-25')
        ->assertOk()
        ->assertJsonPath('period_exports.can_export', false);

    $this->get("/api/mobile/operational-modules/kebersihan/period-export/{$area->id}?start_date=2026-08-24&end_date=2026-09-04")
        ->assertForbidden();
});
