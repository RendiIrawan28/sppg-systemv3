<?php

use App\Enums\NutritionRecordStatus;
use App\Models\FieldDistributionPlan;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\NutritionRequirementPlan;
use App\Models\ProcessingBatch;
use App\Models\ProcurementRequest;
use App\Models\ServiceHoliday;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\FieldDistributionPlanWorkflow;
use App\Services\MenuCycleService;
use App\Services\MenuDayRevisionService;
use App\Services\MenuNutritionCalculator;
use App\Services\MenuNutritionWarningService;
use App\Services\MenuServiceCalendarService;
use App\Services\NutritionRequirementFromBeneficiaryPeriodService;
use App\Services\ProcessingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('keeps every calendar date in the cycle and marks weekends as service holidays', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-LIBUR', 'name' => 'SPPG Libur', 'slug' => 'sppg-libur', 'is_active' => true,
    ]);

    $calendar = app(MenuServiceCalendarService::class);
    $days = $calendar->build($unit->id, '2026-08-21', 5);

    expect(array_column($days, 'day_number'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($days, 'service_date'))->toBe([
            '2026-08-21', '2026-08-22', '2026-08-23', '2026-08-24', '2026-08-25',
        ])
        ->and($calendar->isHoliday($unit->id, '2026-08-22'))->toBeTrue()
        ->and($calendar->isHoliday($unit->id, '2026-08-23'))->toBeTrue()
        ->and($calendar->isHoliday($unit->id, '2026-08-24'))->toBeFalse();
});

it('automatically detaches a draft menu when its date becomes a holiday without deleting the menu', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-DRAFT', 'name' => 'SPPG Draft', 'slug' => 'sppg-draft', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Admin', 'email' => 'holiday-admin@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'MENU-LIBUR', 'name' => 'Menu Lama',
        'service_date' => '2026-08-25', 'status' => 'draft', 'created_by' => $actor->id,
    ]);
    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id, 'name' => 'Siklus Libur', 'start_date' => '2026-08-24',
        'end_date' => '2026-08-28', 'cycle_length_days' => 5,
        'status' => NutritionRecordStatus::Draft, 'created_by' => $actor->id,
    ]);
    $day = MenuCycleDay::query()->create([
        'menu_cycle_id' => $cycle->id, 'day_number' => 2, 'service_date' => '2026-08-25',
        'production_date' => '2026-08-25', 'delivery_date' => '2026-08-25', 'menu_id' => $menu->id,
    ]);

    $this->actingAs($actor);
    ServiceHoliday::query()->create([
        'sppg_unit_id' => $unit->id, 'holiday_date' => '2026-08-25',
        'name' => 'Libur Uji', 'holiday_type' => 'operational', 'is_active' => true,
    ]);

    expect($day->refresh()->menu_id)->toBeNull()
        ->and($day->day_number)->toBe(2)
        ->and($day->service_date->toDateString())->toBe('2026-08-25')
        ->and(Menu::query()->whereKey($menu->id)->exists())->toBeTrue();
});

it('rejects nutrition requirements on an automatic weekend holiday', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-REQ', 'name' => 'SPPG Requirement', 'slug' => 'sppg-requirement', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Ahli Gizi', 'email' => 'holiday-gizi@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id, 'name' => 'Siklus Akhir Pekan', 'start_date' => '2026-08-22',
        'end_date' => '2026-08-22', 'cycle_length_days' => 1,
        'status' => NutritionRecordStatus::Approved, 'created_by' => $actor->id,
    ]);
    $day = MenuCycleDay::query()->create([
        'menu_cycle_id' => $cycle->id, 'day_number' => 1, 'service_date' => '2026-08-22',
        'production_date' => '2026-08-22', 'delivery_date' => '2026-08-22',
    ]);

    app(NutritionRequirementFromBeneficiaryPeriodService::class)->generate($day, $actor);
})->throws(DomainException::class, 'hari libur pelayanan');

it('does not require a menu on a holiday but still requires menus on service days', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-READY', 'name' => 'SPPG Ready', 'slug' => 'sppg-ready', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Ahli Gizi Ready', 'email' => 'holiday-ready@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    ServiceHoliday::query()->create([
        'sppg_unit_id' => $unit->id, 'holiday_date' => '2026-08-25',
        'name' => 'Libur Readiness', 'holiday_type' => 'operational', 'is_active' => true,
    ]);
    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id, 'beneficiary_period_id' => 99,
        'name' => 'Siklus Readiness', 'start_date' => '2026-08-24', 'end_date' => '2026-08-26',
        'cycle_length_days' => 3, 'status' => NutritionRecordStatus::Draft, 'created_by' => $actor->id,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'MENU-READY', 'name' => 'Menu Ready',
        'status' => 'draft', 'created_by' => $actor->id,
    ]);
    foreach ([1 => '2026-08-24', 2 => '2026-08-25', 3 => '2026-08-26'] as $number => $date) {
        MenuCycleDay::query()->create([
            'menu_cycle_id' => $cycle->id, 'day_number' => $number, 'service_date' => $date,
            'production_date' => $date, 'delivery_date' => $date,
            'menu_id' => $number === 1 ? $menu->id : null,
        ]);
    }

    $warnings = Mockery::mock(MenuNutritionWarningService::class);
    $warnings->shouldReceive('blockingIssues')->once()->andReturn([]);
    $warnings->shouldReceive('nutritionWarnings')->once()->andReturn([]);
    $calculator = Mockery::mock(MenuNutritionCalculator::class);
    $calculator->shouldReceive('refresh')->once();
    app()->instance(MenuNutritionCalculator::class, $calculator);

    $report = (new MenuCycleService(app(MenuServiceCalendarService::class), $warnings))->readinessReport($cycle);

    expect($report['blocking'])
        ->not->toContain('Hari ke-2 belum memiliki menu.')
        ->toContain('Hari ke-3 belum memiliki menu.');
});

it('requires approval before detaching a menu from an approved cycle', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-LOCK', 'name' => 'SPPG Locked', 'slug' => 'sppg-locked', 'is_active' => true,
    ]);
    $requester = User::query()->create([
        'name' => 'Pengaju Libur', 'email' => 'holiday-requester@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $approver = User::query()->create([
        'name' => 'Penyetuju Libur', 'email' => 'holiday-approver@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'MENU-LOCK', 'name' => 'Menu Terkunci',
        'service_date' => '2026-08-27', 'status' => 'approved', 'created_by' => $requester->id,
    ]);
    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id, 'name' => 'Siklus Terkunci', 'start_date' => '2026-08-27',
        'end_date' => '2026-08-27', 'cycle_length_days' => 1,
        'status' => NutritionRecordStatus::Approved, 'created_by' => $requester->id,
    ]);
    $day = MenuCycleDay::query()->create([
        'menu_cycle_id' => $cycle->id, 'day_number' => 1, 'service_date' => '2026-08-27',
        'production_date' => '2026-08-27', 'delivery_date' => '2026-08-27', 'menu_id' => $menu->id,
    ]);

    $this->actingAs($requester);
    ServiceHoliday::query()->create([
        'sppg_unit_id' => $unit->id, 'holiday_date' => '2026-08-27',
        'name' => 'Libur Setelah Disetujui', 'holiday_type' => 'operational', 'is_active' => true,
    ]);

    $day->refresh();
    expect($day->menu_id)->toBe($menu->id)
        ->and($day->latestRevisionRequest()->exists())->toBeTrue();

    $this->actingAs($approver);
    app(MenuDayRevisionService::class)->authorize($day->latestRevisionRequest()->firstOrFail(), $approver);

    expect($day->refresh()->menu_id)->toBeNull()
        ->and(Menu::query()->whereKey($menu->id)->exists())->toBeTrue();
});

it('cancels unprocessed requirements and procurement when a service holiday is added', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-CANCEL', 'name' => 'SPPG Cancel', 'slug' => 'sppg-cancel', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Admin Cancel', 'email' => 'holiday-cancel@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $requirement = NutritionRequirementPlan::query()->create([
        'sppg_unit_id' => $unit->id, 'requirement_date' => '2026-08-28',
        'total_portions' => 100, 'status' => NutritionRecordStatus::Draft, 'created_by' => $actor->id,
    ]);
    $procurement = ProcurementRequest::query()->create([
        'sppg_unit_id' => $unit->id, 'request_date' => '2026-08-24', 'needed_date' => '2026-08-28',
        'nutrition_requirement_plan_id' => $requirement->id, 'status' => ProcurementRequest::STATUS_DRAFT,
        'price_status' => 'draft', 'created_by' => $actor->id,
    ]);

    $this->actingAs($actor);
    ServiceHoliday::query()->create([
        'sppg_unit_id' => $unit->id, 'holiday_date' => '2026-08-28',
        'name' => 'Libur Pembatalan', 'holiday_type' => 'operational', 'is_active' => true,
    ]);

    expect($requirement->refresh()->status)->toBe(NutritionRecordStatus::Cancelled)
        ->and($procurement->refresh()->status)->toBe(ProcurementRequest::STATUS_CANCELLED)
        ->and($procurement->price_status)->toBe('cancelled');
});

it('blocks distribution plan activation issues on weekends', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-DIST', 'name' => 'SPPG Distribution', 'slug' => 'sppg-distribution', 'is_active' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id, 'distribution_date' => '2026-08-29',
        'service_date' => '2026-08-29', 'production_date' => '2026-08-29',
        'status' => 'draft', 'planned_total_portions' => 0, 'confirmed_beneficiaries' => 0,
    ]);

    $issues = app(FieldDistributionPlanWorkflow::class)->submissionIssues($plan);

    expect(collect($issues)->contains(fn (string $issue): bool => str_contains($issue, 'hari libur pelayanan')))->toBeTrue();
});

it('allows processing on a holiday when its target service date is operational', function (): void {
    $this->travelTo(Carbon::parse('2026-08-23 09:00:00'));
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-H1', 'name' => 'SPPG H-1', 'slug' => 'sppg-h1', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Petugas H-1', 'email' => 'petugas-h1@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => '2026-08-23',
        'service_date' => '2026-08-24',
        'menu_name_snapshot' => 'Menu Senin',
        'product_name' => 'Menu Senin',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'state' => 'planned',
        'status' => 'draft',
    ]);

    app(ProcessingWorkflow::class)->start($batch, $actor);

    expect($batch->refresh()->state->value)->toBe('in_progress');
});
