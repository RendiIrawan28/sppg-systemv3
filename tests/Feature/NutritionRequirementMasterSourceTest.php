<?php

use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodCategoryTotal;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\NutritionRequirementPlan;
use App\Models\ProcurementRequest;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\NutritionRequirementCalculator;
use App\Services\NutritionRequirementFromBeneficiaryPeriodService;
use App\Services\ProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-08-24 09:00:00')));

it('uses 3200 master recipients instead of 3050 operational portions and stays idempotent', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-NUT', 'name' => 'SPPG Nutrisi', 'slug' => 'sppg-nutrisi', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Ahli Gizi', 'email' => 'gizi-master@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $period = BeneficiaryPeriod::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'PER-NUT', 'name' => 'Periode 3.200',
        'start_date' => today(), 'end_date' => today()->addMonth(), 'status' => 'active',
        'active_members' => 3200, 'total_members' => 3200, 'destination_count' => 1,
    ]);
    $destination = BeneficiaryPeriodDestination::query()->create([
        'beneficiary_period_id' => $period->id, 'destination_key' => 'school:3200',
        'destination_type' => 'school', 'destination_id' => 3200,
        'destination_name_snapshot' => 'Sekolah Master', 'sort_order' => 1, 'is_active' => true,
    ]);
    BeneficiaryPeriodCategoryTotal::query()->create([
        'beneficiary_period_id' => $period->id,
        'beneficiary_period_destination_id' => $destination->id,
        'beneficiary_category_code_snapshot' => 'KECIL',
        'beneficiary_category_name_snapshot' => 'Porsi Kecil',
        'portion_category' => 'small', 'menu_audience' => 'student', 'total_beneficiaries' => 1200,
    ]);
    BeneficiaryPeriodCategoryTotal::query()->create([
        'beneficiary_period_id' => $period->id,
        'beneficiary_period_destination_id' => $destination->id,
        'beneficiary_category_code_snapshot' => 'BESAR',
        'beneficiary_category_name_snapshot' => 'Porsi Besar',
        'portion_category' => 'large', 'menu_audience' => 'student', 'total_beneficiaries' => 2000,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'MENU-NUT', 'name' => 'Menu Master',
        'service_date' => today(), 'planned_portions' => 3050, 'status' => 'approved', 'created_by' => $actor->id,
    ]);
    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id, 'beneficiary_period_id' => $period->id,
        'name' => 'Siklus Master', 'start_date' => today(), 'end_date' => today()->addMonth(),
        'cycle_length_days' => 1, 'buffer_percent' => 3, 'status' => 'approved', 'created_by' => $actor->id,
    ]);
    $day = MenuCycleDay::query()->create([
        'menu_cycle_id' => $cycle->id, 'day_number' => 1, 'service_date' => today(),
        'production_date' => today(), 'delivery_date' => today(), 'menu_id' => $menu->id,
    ]);

    $calculator = Mockery::mock(NutritionRequirementCalculator::class);
    $calculator->shouldReceive('generate')->twice()->with(Mockery::type(NutritionRequirementPlan::class));
    $procurement = Mockery::mock(ProcurementRequestService::class);
    $procurement->shouldReceive('createOrSynchronizeDraft')->twice()
        ->andReturn(Mockery::mock(ProcurementRequest::class));
    $service = new NutritionRequirementFromBeneficiaryPeriodService($calculator, $procurement);

    $first = $service->generate($day, $actor);
    $second = $service->generate($day->refresh(), $actor);

    expect($first->id)->toBe($second->id)
        ->and($second->source_type)->toBe('beneficiary_period_master')
        ->and($second->beneficiary_period_id)->toBe($period->id)
        ->and($second->field_distribution_plan_id)->toBeNull()
        ->and($second->total_portions)->toBe(3200)
        ->and((float) $second->buffer_percent)->toBe(3.0)
        ->and(NutritionRequirementPlan::query()->where('menu_cycle_day_id', $day->id)->count())->toBe(1);
});
