<?php

use App\Enums\UserRole;
use App\Livewire\V3\Nutrition\IngredientNutritions;
use App\Livewire\V3\Nutrition\Menus\Form as MenuRecipeForm;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\NutritionComponent;
use App\Models\User;
use App\Services\IngredientNutritionReferenceService;
use App\Services\MenuNutritionCalculator;
use App\Support\AccessControl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.nutrition_reference_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    DB::purge('nutrition_reference_test');
    DB::setDefaultConnection('nutrition_reference_test');
    expect(DB::connection()->getConfig('database'))->toBe(':memory:');
    Schema::create('sppg_units', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active');
    });
    DB::table('sppg_units')->insert(['id' => 1, 'name' => 'Unit pengujian', 'is_active' => true]);
    Schema::create('measurement_units', function (Blueprint $table) {
        $table->id();
        $table->string('code');
        $table->string('name');
        $table->string('symbol')->nullable();
        $table->string('unit_type');
        $table->decimal('to_base_factor')->nullable();
    });
    Schema::create('ingredients', function (Blueprint $table) {
        $table->id();
        $table->integer('sppg_unit_id');
        $table->integer('measurement_unit_id');
        $table->string('code');
        $table->string('name');
        $table->decimal('nutrition_reference_grams')->nullable();
        $table->string('nutrition_source')->nullable();
        $table->boolean('is_active');
        $table->decimal('grams_per_unit')->nullable();
    });
    Schema::create('nutrition_components', function (Blueprint $table) {
        $table->id();
        $table->string('code');
        $table->string('name');
        $table->string('unit');
        $table->integer('sort_order');
    });
    Schema::create('ingredient_nutritions', function (Blueprint $table) {
        $table->id();
        $table->integer('ingredient_id');
        $table->integer('nutrition_component_id');
        $table->decimal('value_per_100g')->nullable();
        $table->string('source')->nullable();
    });
    $this->service = new IngredientNutritionReferenceService;
    $this->gramUnit = DB::table('measurement_units')->insertGetId(['code' => 'g', 'name' => 'Gram', 'symbol' => 'g', 'unit_type' => 'weight', 'to_base_factor' => 1]);
    $this->pieceUnit = DB::table('measurement_units')->insertGetId(['code' => 'pcs', 'name' => 'Buah', 'symbol' => 'pcs', 'unit_type' => 'count', 'to_base_factor' => 1]);
    foreach (IngredientNutritionReferenceService::PRIMARY_CODES as $order => $code) {
        DB::table('nutrition_components')->insert(['code' => $code, 'name' => ucfirst($code), 'unit' => $code === 'energy' ? 'kcal' : 'g', 'sort_order' => $order]);
    }
});

afterEach(function () {
    DB::purge('nutrition_reference_test');
    DB::setDefaultConnection($this->originalConnection);
});

function nutritionTestIngredient(string $code, int $unitId, ?float $basis, array $values): Ingredient
{
    $id = DB::table('ingredients')->insertGetId(['sppg_unit_id' => 1, 'measurement_unit_id' => $unitId, 'code' => $code, 'name' => $code, 'nutrition_reference_grams' => $basis, 'is_active' => true]);
    foreach ($values as $componentCode => $value) {
        DB::table('ingredient_nutritions')->insert(['ingredient_id' => $id, 'nutrition_component_id' => DB::table('nutrition_components')->where('code', $componentCode)->value('id'), 'value_per_100g' => $value]);
    }

    return Ingredient::query()->with(['measurementUnit', 'nutritions.component'])->findOrFail($id);
}

it('labels 100 gram and one piece reference bases without assuming every item is per 100 gram', function () {
    $grain = nutritionTestIngredient('TEMPE', $this->gramUnit, 100, array_fill_keys(IngredientNutritionReferenceService::PRIMARY_CODES, 0));
    $piece = nutritionTestIngredient('TELUR', $this->pieceUnit, 1, array_fill_keys(IngredientNutritionReferenceService::PRIMARY_CODES, 1));
    expect($this->service->basisLabel($grain))->toBe('per 100 g')
        ->and($this->service->basisLabel($piece))->toBe('per 1 pcs')
        ->and($this->service->statusFlags($grain))->toBe(['Lengkap'])
        ->and($this->service->statusFlags($piece))->toBe(['Lengkap']);
});

it('keeps missing nutrients and suspicious bases visible as separate flags and SQL filters', function () {
    $complete = nutritionTestIngredient('LENGKAP', $this->gramUnit, 100, array_fill_keys(IngredientNutritionReferenceService::PRIMARY_CODES, 1));
    $missing = nutritionTestIngredient('KURANG', $this->gramUnit, 100, ['protein' => 20]);
    $basis = nutritionTestIngredient('BASIS', $this->pieceUnit, 100, array_fill_keys(IngredientNutritionReferenceService::PRIMARY_CODES, 1));
    $both = nutritionTestIngredient('KEDUANYA', $this->pieceUnit, 100, ['protein' => 20]);
    expect($this->service->statusFlags($missing))->toBe(['Belum lengkap'])
        ->and($this->service->statusFlags($basis))->toBe(['Periksa basis'])
        ->and($this->service->statusFlags($both))->toBe(['Belum lengkap', 'Periksa basis']);
    $filtered = fn ($status) => $this->service->filterStatus(Ingredient::query()->where('sppg_unit_id', 1), $status)->pluck('code')->sort()->values()->all();
    expect($filtered('complete'))->toBe([$complete->code])
        ->and($filtered('incomplete'))->toBe(['KEDUANYA', 'KURANG'])
        ->and($filtered('check_basis'))->toBe(['BASIS', 'KEDUANYA']);
});

it('uses the main calculator formula for unsaved gramasi and its toddler maternal fallbacks', function () {
    $ingredient = nutritionTestIngredient('PROTEIN', $this->gramUnit, 100, ['protein' => 20]);
    $components = NutritionComponent::query()->orderBy('sort_order')->get();
    $draft = ['grams_per_unit_snapshot' => 1, 'input_quantity_small' => 32, 'input_quantity_large' => 40, 'input_quantity_toddler' => null, 'input_quantity_maternal' => null];
    $detail = $this->service->details($ingredient, $components, $draft, MeasurementUnit::find($this->gramUnit));
    $protein = collect($detail['components'])->firstWhere('code', 'protein');
    $nutrition = $ingredient->nutritions->firstWhere('nutrition_component_id', $protein ? NutritionComponent::where('code', 'protein')->value('id') : 0);
    expect($protein['contributions'])->toBe(['small' => 6.4, 'large' => 8.0, 'toddler' => 6.4, 'maternal' => 8.0])
        ->and($protein['contributions']['small'])->toBe(MenuNutritionCalculator::ingredientContribution($ingredient, $nutrition, 32))
        ->and($detail['profiles']['toddler']['fallback'])->toBe('Porsi Kecil')
        ->and($detail['profiles']['maternal']['fallback'])->toBe('Porsi Besar');
    $draft['input_quantity_small'] = 40;
    $updated = $this->service->details($ingredient, $components, $draft, MeasurementUnit::find($this->gramUnit));
    expect(collect($updated['components'])->firstWhere('code', 'protein')['contributions']['small'])->toBe(8.0);
});

it('handles absent quantity and nutrient values and warns about count conversion without changing results', function () {
    $ingredient = nutritionTestIngredient('PCS', $this->pieceUnit, 1, ['protein' => 20]);
    $components = NutritionComponent::query()->orderBy('sort_order')->get();
    $draft = ['grams_per_unit_snapshot' => 60, 'input_quantity_small' => 1, 'input_quantity_large' => null, 'input_quantity_toddler' => null, 'input_quantity_maternal' => null];
    $detail = $this->service->details($ingredient, $components, $draft, MeasurementUnit::find($this->pieceUnit));
    $protein = collect($detail['components'])->firstWhere('code', 'protein');
    $fat = collect($detail['components'])->firstWhere('code', 'fat');
    expect($detail['conversion_warning'])->toBeTrue()
        ->and($protein['contributions']['small'])->toBe(1200.0)
        ->and($protein['contributions']['large'])->toBeNull()
        ->and($fat['contributions']['small'])->toBeNull();
    $empty = nutritionTestIngredient('KOSONG', $this->gramUnit, null, []);
    $emptyDetail = $this->service->details($empty, $components);
    expect($emptyDetail['has_nutrition'])->toBeFalse()
        ->and($emptyDetail['basis_fallback'])->toBeTrue()
        ->and(view('livewire.v3.nutrition.partials.ingredient-nutrition-modal', ['detail' => $emptyDetail, 'closeAction' => 'closeIngredient'])->render())
        ->toContain('Data nilai gizi bahan belum tersedia.')->toContain('memakai angka 100 sebagai fallback');
});

it('sums duplicate component rows like the calculator instead of hiding one row', function () {
    $ingredient = nutritionTestIngredient('DUPLIKAT', $this->gramUnit, 100, ['protein' => 20]);
    DB::table('ingredient_nutritions')->insert(['ingredient_id' => $ingredient->id, 'nutrition_component_id' => NutritionComponent::where('code', 'protein')->value('id'), 'value_per_100g' => 5]);
    $ingredient->load(['nutritions.component']);
    $detail = $this->service->details($ingredient, NutritionComponent::all(), ['grams_per_unit_snapshot' => 1, 'input_quantity_small' => 32], MeasurementUnit::find($this->gramUnit));
    $protein = collect($detail['components'])->firstWhere('code', 'protein');
    expect($this->service->primaryNutritionRows($ingredient)->get('protein')->value_per_100g)->toBe(25.0)
        ->and($protein['reference'])->toBe(25.0)
        ->and($protein['contributions']['small'])->toBe(8.0);
});

it('pages active ingredients and filters the scoped source list before pagination', function () {
    for ($i = 1; $i <= 22; $i++) {
        nutritionTestIngredient('BAHAN-'.$i, $this->gramUnit, 100, ['protein' => 20]);
    }
    DB::table('ingredients')->where('code', 'BAHAN-21')->update(['is_active' => false]);
    DB::table('ingredients')->where('code', 'BAHAN-1')->update(['nutrition_source' => 'TKPI']);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_active = true;
    $user->is_super_admin = false;
    $user->setRelation('roles', new EloquentCollection);
    $user->shouldReceive('can')->andReturnUsing(fn ($permission) => $permission === 'nutrition.view');
    auth()->setUser($user);
    $page = new IngredientNutritions;
    $data = $page->render($this->service)->getData();
    expect($data['ingredients']->total())->toBe(21)->and($data['ingredients']->count())->toBe(20);
    $page->source = 'TKPI';
    $filtered = $page->render($this->service)->getData();
    expect($filtered['ingredients']->total())->toBe(1)
        ->and($filtered['ingredients']->first()->code)->toBe('BAHAN-1');
    $page->source = '';
    $page->search = 'BAHAN-2';
    expect($page->render($this->service)->getData()['ingredients']->total())->toBe(3);
    $page->search = '';
    $page->unitFilter = (string) $this->pieceUnit;
    expect($page->render($this->service)->getData()['ingredients']->total())->toBe(0);
    $page->unitFilter = '';
    $page->statusFilter = 'complete';
    expect($page->render($this->service)->getData()['ingredients']->total())->toBe(0);
    expect($page->render($this->service)->render())->toContain('Nilai Gizi Bahan')->toContain('Belum ada data nilai gizi bahan.');
});

it('previews current unsaved recipe input and rejects ingredients outside the active unit', function () {
    $ingredient = nutritionTestIngredient('RESEP', $this->gramUnit, 100, ['protein' => 20]);
    $foreign = DB::table('ingredients')->insertGetId(['sppg_unit_id' => 2, 'measurement_unit_id' => $this->gramUnit, 'code' => 'ASING', 'name' => 'Asing', 'nutrition_reference_grams' => 100, 'is_active' => true]);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_active = true;
    $user->is_super_admin = false;
    $user->shouldReceive('can')->andReturnUsing(fn ($permission) => in_array($permission, ['nutrition.view', 'menus.view'], true));
    auth()->setUser($user);
    $form = new MenuRecipeForm;
    $form->items = [['ingredients' => [['ingredient_id' => $ingredient->id, 'measurement_unit_id' => $this->gramUnit, 'grams_per_unit_snapshot' => 1, 'input_quantity_small' => 32, 'input_quantity_large' => 40, 'input_quantity_toddler' => null, 'input_quantity_maternal' => null]]]];
    $before = DB::table('ingredients')->count();
    $form->showIngredientNutrition(0, 0, $this->service);
    $protein = collect($form->nutritionPreview['components'])->firstWhere('code', 'protein');
    expect($protein['contributions']['small'])->toBe(6.4);
    $form->items[0]['ingredients'][0]['input_quantity_small'] = 40;
    $form->showIngredientNutrition(0, 0, $this->service);
    expect(collect($form->nutritionPreview['components'])->firstWhere('code', 'protein')['contributions']['small'])->toBe(8.0)
        ->and(DB::table('ingredients')->count())->toBe($before);
    $form->items[0]['ingredients'][0]['ingredient_id'] = $foreign;
    expect(fn () => $form->showIngredientNutrition(0, 0, $this->service))->toThrow(ModelNotFoundException::class);
});

it('keeps list query count constant as ingredient rows grow within one page', function () {
    nutritionTestIngredient('SATU', $this->gramUnit, 100, ['protein' => 20]);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_active = true;
    $user->is_super_admin = false;
    $user->setRelation('roles', new EloquentCollection);
    $user->shouldReceive('can')->andReturnUsing(fn ($permission) => $permission === 'nutrition.view');
    auth()->setUser($user);
    $page = new IngredientNutritions;
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page->render($this->service)->render();
    $smallCount = count(DB::getQueryLog());
    for ($i = 2; $i <= 20; $i++) {
        nutritionTestIngredient('BAHAN-'.$i, $this->gramUnit, 100, ['protein' => 20]);
    }
    DB::flushQueryLog();
    $page->render($this->service)->render();
    $largeCount = count(DB::getQueryLog());
    expect($largeCount)->toBe($smallCount);
});

it('uses the existing nutrition permission for page access', function () {
    foreach ([UserRole::AhliGizi, UserRole::AdminSppg, UserRole::KepalaSppg] as $role) {
        expect(AccessControl::permissionsForRole($role->value))->toContain('nutrition.view');
    }
    expect(Route::has('v3.nutrition.ingredient-nutritions'))->toBeTrue();
    $allowed = Mockery::mock(User::class)->makePartial();
    $allowed->is_super_admin = false;
    $allowed->shouldReceive('can')->with('nutrition.view')->andReturn(true);
    auth()->setUser($allowed);
    (new IngredientNutritions)->mount();
    $denied = Mockery::mock(User::class)->makePartial();
    $denied->is_super_admin = false;
    $denied->shouldReceive('can')->with('nutrition.view')->andReturn(false);
    auth()->setUser($denied);
    expect(fn () => (new IngredientNutritions)->mount())->toThrow(HttpException::class);
});
