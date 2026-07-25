<?php

use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryPeriod;
use App\Models\FieldDistributionPlan;
use App\Models\Ingredient;
use App\Models\IngredientNutrition;
use App\Models\Menu;
use App\Models\MenuCategoryTarget;
use App\Models\MenuCycle;
use App\Models\MenuItem;
use App\Models\NutritionComponent;
use App\Models\NutritionStandard;
use App\Models\RecipeIngredient;
use App\Models\School;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\BeneficiaryPeriodAggregateService;
use App\Services\FieldPlanActualConfirmationService;
use App\Services\MenuMatrixService;
use App\Services\MenuNutritionCalculator;
use App\Services\NutritionistPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores aggregate recipients per destination and category for menu planning', function (): void {
    $unit = testSimplifiedUnit();
    $actor = User::factory()->create();
    $school = School::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'SD-NGT',
        'name' => 'SD N Nogotirto',
        'education_level' => 'SD',
        'is_active' => true,
    ]);
    $small = testCategory($unit, 'kelas_1_3', 'Kelas 1-3', 'small');
    $large = testCategory($unit, 'kelas_4_6', 'Kelas 4-6', 'large');
    $period = BeneficiaryPeriod::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'JP-001',
        'name' => 'Jumlah Penerima Uji',
        'start_date' => today(),
        'end_date' => today()->addDays(13),
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);

    app(BeneficiaryPeriodAggregateService::class)->save($period, [[
        'destination_key' => "school:{$school->id}",
        'counts' => [$small->id => 220, $large->id => 400],
    ]], $actor);

    $period->refresh();
    $summary = app(NutritionistPlanningService::class)->summary($period, 0);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'menu_id' => 1,
        'menu_cycle_day_id' => 1,
        'distribution_date' => today()->addDays(3),
        'service_date' => today()->addDays(3),
        'production_date' => today()->addDays(2),
        'menu_name_snapshot' => 'Menu Uji',
        'shift' => 'morning',
        'status' => 'draft',
    ]);
    app(FieldPlanActualConfirmationService::class)->synchronize($plan, $actor);
    $plan->load('destinations.recipientGroups');

    expect($period->status)->toBe('active')
        ->and($period->destination_count)->toBe(1)
        ->and($period->active_members)->toBe(620)
        ->and($period->members()->count())->toBe(0)
        ->and($period->categoryTotals()->sum('total_beneficiaries'))->toEqual(620)
        ->and($summary['base_small_portions'])->toBe(220)
        ->and($summary['base_large_portions'])->toBe(400)
        ->and($summary['base_total_portions'])->toBe(620)
        ->and($plan->destinations)->toHaveCount(1)
        ->and($plan->destinations->first()->confirmed_beneficiaries)->toBe(620)
        ->and($plan->destinations->first()->recipientGroups)->toHaveCount(2);
});

it('compares one menu portion with a full day nutrition requirement', function (): void {
    $unit = testSimplifiedUnit();
    $actor = User::factory()->create();
    $category = testCategory($unit, 'kelas_1_3', 'Kelas 1-3', 'small');
    $component = NutritionComponent::query()->create([
        'code' => 'energy',
        'name' => 'Energi',
        'unit' => 'kkal',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    NutritionStandard::query()->create([
        'sppg_unit_id' => $unit->id,
        'beneficiary_category_id' => $category->id,
        'nutrition_component_id' => $component->id,
        'target_value' => 1000,
        'effective_from' => today()->subDay(),
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'BAHAN-01',
        'name' => 'Bahan Uji',
        'category' => 'staple',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
    IngredientNutrition::query()->create([
        'ingredient_id' => $ingredient->id,
        'nutrition_component_id' => $component->id,
        'value_per_100g' => 300,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'MENU-01',
        'name' => 'Menu Uji',
        'service_date' => today(),
        'status' => 'draft',
        'planned_portions' => 1,
        'created_by' => $actor->id,
    ]);
    MenuCategoryTarget::query()->create([
        'menu_id' => $menu->id,
        'beneficiary_category_id' => $category->id,
        'portion_multiplier' => 1,
    ]);
    $item = MenuItem::query()->create([
        'menu_id' => $menu->id,
        'name' => 'Hidangan Uji',
        'item_type' => 'staple',
        'menu_audience' => 'all',
        'sort_order' => 1,
    ]);
    RecipeIngredient::query()->create([
        'menu_item_id' => $item->id,
        'ingredient_id' => $ingredient->id,
        'quantity_grams' => 100,
        'quantity_small_grams' => 100,
        'quantity_large_grams' => 100,
    ]);

    app(MenuNutritionCalculator::class)->refresh($menu);
    $summary = $menu->nutritionSummaries()->firstOrFail();

    expect((float) $summary->value_per_portion)->toBe(300.0)
        ->and((float) $summary->standard_target)->toBe(1000.0)
        ->and((float) $summary->achievement_percent)->toBe(30.0);
});

it('automatically syncs menu recipient groups from aggregate period totals', function (): void {
    $unit = testSimplifiedUnit();
    $actor = User::factory()->create();
    $school = School::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'SD-AUTO',
        'name' => 'SD Otomatis',
        'education_level' => 'SD',
        'is_active' => true,
    ]);
    $small = testCategory($unit, 'kelas_1_3_auto', 'Kelas 1-3', 'small');
    $large = testCategory($unit, 'kelas_4_6_auto', 'Kelas 4-6', 'large');
    $period = BeneficiaryPeriod::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'JP-AUTO',
        'name' => 'Jumlah Penerima Otomatis',
        'start_date' => today(),
        'end_date' => today()->addDays(13),
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);

    app(BeneficiaryPeriodAggregateService::class)->save($period, [[
        'destination_key' => "school:{$school->id}",
        'counts' => [$small->id => 220, $large->id => 400],
    ]], $actor);

    $cycle = MenuCycle::query()->create([
        'sppg_unit_id' => $unit->id,
        'beneficiary_period_id' => $period->id,
        'name' => 'Siklus Otomatis',
        'start_date' => today(),
        'end_date' => today()->addDays(4),
        'cycle_length_days' => 5,
        'created_by' => $actor->id,
    ]);
    $menu = Menu::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'MENU-AUTO',
        'name' => 'Menu Otomatis',
        'service_date' => today(),
        'status' => 'draft',
        'planned_portions' => 620,
        'created_by' => $actor->id,
    ]);

    app(MenuMatrixService::class)->syncCategoryTargets($menu, $cycle);

    expect($menu->categoryTargets()->pluck('beneficiary_category_id')->sort()->values()->all())
        ->toBe(collect([$small->id, $large->id])->sort()->values()->all());
});

function testSimplifiedUnit(): SppgUnit
{
    return SppgUnit::query()->create([
        'code' => fake()->unique()->bothify('UNIT-###??'),
        'name' => fake()->company(),
        'slug' => fake()->unique()->slug(),
        'is_active' => true,
    ]);
}

function testCategory(SppgUnit $unit, string $code, string $name, string $portion): BeneficiaryCategory
{
    return BeneficiaryCategory::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => $code,
        'name' => $name,
        'group_type' => 'student',
        'portion_size' => $portion,
        'menu_audience' => 'student',
        'sort_order' => 1,
        'is_active' => true,
    ]);
}
