<?php

namespace App\Livewire\V3\Nutrition\Menus;

use App\Enums\MenuAudience;
use App\Enums\MenuComponentType;
use App\Enums\MenuPortionProfile;
use App\Enums\MenuStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryCategory;
use App\Models\Ingredient;
use App\Models\IngredientPortionStandard;
use App\Models\MeasurementUnit;
use App\Models\Menu;
use App\Services\MenuApprovalService;
use App\Services\MenuDayRevisionService;
use App\Services\MenuNutritionCalculator;
use App\Services\MenuNutritionWarningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    use InteractsWithV3Shell;

    public int $menuId;

    public string $code = '';

    public string $name = '';

    public int $plannedPortions = 0;

    public string $notes = '';

    public string $decisionNotes = '';

    public ?string $actionMessage = null;

    public bool $showSpecialGramasi = false;

    public function toggleSpecialGramasi(): void
    {
        $this->showSpecialGramasi = ! $this->showSpecialGramasi;
    }

    /** @var array<int, array<string, mixed>> */
    public array $categories = [];

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public function mount(Menu $menu): void
    {
        abort_unless($this->allowed('menus.view'), 403);
        abort_unless((int) $menu->sppg_unit_id === (int) $this->currentUnit()->getKey(), 404);
        $this->menuId = $menu->getKey();
        $this->fillFromMenu($menu);
    }

    public function addCategory(): void
    {
        $this->categories[] = ['_id' => null, 'beneficiary_category_id' => '', 'portion_multiplier' => 1];
    }

    public function removeCategory(int $index): void
    {
        unset($this->categories[$index]);
        $this->categories = array_values($this->categories);
    }

    public function addItem(): void
    {
        $this->items[] = ['_id' => null, 'name' => '', 'item_type' => 'staple', 'menu_audience' => 'all',
            'portion_weight_small_grams' => 0, 'portion_weight_large_grams' => 0, 'portion_weight_toddler_grams' => 0,
            'portion_weight_maternal_grams' => 0, 'sort_order' => count($this->items) + 1, 'preparation_notes' => '', 'ingredients' => []];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function addIngredient(int $itemIndex): void
    {
        $this->items[$itemIndex]['ingredients'][] = ['_id' => null, 'ingredient_id' => '', 'measurement_unit_id' => '',
            'grams_per_unit_snapshot' => '', 'input_quantity_small' => 0, 'input_quantity_large' => 0,
            'input_quantity_toddler' => 0, 'input_quantity_maternal' => 0, 'ingredient_portion_standard_id' => null,
            'portion_source' => 'manual', 'portion_override' => false, 'cooking_loss_percent' => 0, 'notes' => ''];
    }

    public function applyIngredientStandard(int $itemIndex, int $ingredientIndex): void
    {
        $ingredientId = (int) ($this->items[$itemIndex]['ingredients'][$ingredientIndex]['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) {
            return;
        }

        $unitId = (int) $this->currentUnit()->getKey();
        $ingredient = Ingredient::query()->where('sppg_unit_id', $unitId)->with('measurementUnit')->findOrFail($ingredientId);
        $standard = IngredientPortionStandard::query()
            ->where('sppg_unit_id', $unitId)->where('ingredient_id', $ingredientId)->where('is_active', true)->first();
        $row = &$this->items[$itemIndex]['ingredients'][$ingredientIndex];

        if ($standard) {
            $row['ingredient_portion_standard_id'] = $standard->getKey();
            $row['portion_source'] = 'standard';
            $row['portion_override'] = false;
            $row['measurement_unit_id'] = $standard->measurement_unit_id;
            $row['grams_per_unit_snapshot'] = (float) $standard->grams_per_unit;
            $row['input_quantity_small'] = (float) $standard->small_quantity;
            $row['input_quantity_large'] = (float) $standard->large_quantity;
            $row['input_quantity_toddler'] = (float) ($standard->toddler_quantity ?? $standard->small_quantity);
            $row['input_quantity_maternal'] = (float) ($standard->maternal_quantity ?? $standard->large_quantity);
            if (filled($standard->component_type)) {
                $this->items[$itemIndex]['item_type'] = $standard->component_type;
            }
        } else {
            $gramUnitId = MeasurementUnit::query()->whereIn('code', ['g', 'gram'])->value('id');
            $row['ingredient_portion_standard_id'] = null;
            $row['portion_source'] = 'manual';
            $row['portion_override'] = true;
            $row['measurement_unit_id'] = $gramUnitId ?: $ingredient->measurement_unit_id;
            $row['grams_per_unit_snapshot'] = 1;
            $row['input_quantity_small'] = 0;
            $row['input_quantity_large'] = 0;
            $row['input_quantity_toddler'] = 0;
            $row['input_quantity_maternal'] = 0;
        }

        $this->refreshItemWeights($itemIndex);
    }

    public function enablePortionOverride(int $itemIndex, int $ingredientIndex): void
    {
        $this->items[$itemIndex]['ingredients'][$ingredientIndex]['portion_override'] = true;
        $this->items[$itemIndex]['ingredients'][$ingredientIndex]['portion_source'] = 'override';
    }

    public function restoreIngredientStandard(int $itemIndex, int $ingredientIndex): void
    {
        $this->applyIngredientStandard($itemIndex, $ingredientIndex);
    }

    public function updateItemWeights(int $itemIndex): void
    {
        $this->refreshItemWeights($itemIndex);
    }

    public function removeIngredient(int $itemIndex, int $ingredientIndex): void
    {
        unset($this->items[$itemIndex]['ingredients'][$ingredientIndex]);
        $this->items[$itemIndex]['ingredients'] = array_values($this->items[$itemIndex]['ingredients']);
        $this->refreshItemWeights($itemIndex);
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $menu = $this->persist();
            app(MenuNutritionCalculator::class)->refresh($menu);
            $this->fillFromMenu($menu->refresh());

            return 'Menu, gramasi, dan bahan resep berhasil disimpan serta nilai gizi dihitung ulang.';
        });
    }

    public function recalculate(): void
    {
        $this->runAction(function (): string {
            $menu = $this->menu();
            if ($menu->isEditable()) {
                $menu = $this->persist();
            }
            app(MenuNutritionCalculator::class)->refresh($menu);
            $warnings = app(MenuNutritionWarningService::class)->nutritionWarnings($menu->refresh());
            session()->flash(
                'v3.status',
                $warnings === []
                    ? 'Nilai gizi berhasil dihitung ulang.'
                    : 'Perhitungan selesai. '.implode(' ', $warnings),
            );
            $this->redirectRoute('v3.nutrition.menus.nutrition', ['menu' => $menu], navigate: true);

            return 'Membuka hasil kebutuhan gizi harian.';
        });
    }

    public function submitRevision(): void
    {
        abort_unless($this->allowed('menus.submit'), 403);
        $this->runAction(function (): string {
            if (blank($this->decisionNotes)) {
                throw ValidationException::withMessages(['decisionNotes' => 'Catatan revisi wajib diisi.']);
            }
            $menu = $this->persist();
            app(MenuApprovalService::class)->submit($menu, auth()->user(), $this->decisionNotes);
            $this->decisionNotes = '';
            $this->fillFromMenu($menu->refresh());

            return 'Revisi menu diajukan.';
        });
    }

    public function approveRevision(): void
    {
        abort_unless($this->allowed('menus.approve'), 403);
        $this->runAction(function (): string {
            app(MenuApprovalService::class)->approve($this->menu(), auth()->user(), trim($this->decisionNotes) ?: null);
            $this->decisionNotes = '';
            $this->fillFromMenu($this->menu()->refresh());

            return 'Revisi menu disetujui.';
        });
    }

    public function requestRevision(): void
    {
        abort_unless($this->allowed('menus.approve'), 403);
        $this->runAction(function (): string {
            if (blank($this->decisionNotes)) {
                throw ValidationException::withMessages(['decisionNotes' => 'Alasan revisi wajib diisi.']);
            }
            app(MenuApprovalService::class)->requestRevision($this->menu(), auth()->user(), $this->decisionNotes);
            $this->decisionNotes = '';
            $this->fillFromMenu($this->menu()->refresh());

            return 'Menu dikembalikan untuk diperbaiki.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('menus.delete'), 403);
        $menu = $this->menu();
        abort_unless($menu->status === MenuStatus::Draft && ! $menu->cycleDays()->exists(), 403);
        $menu->delete();
        session()->flash('v3.status', 'Menu draft berhasil dihapus.');
        $this->redirectRoute('v3.nutrition.menu-matrix', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $menu = $this->menu();
        $activeRevision = app(MenuDayRevisionService::class)->activeRequestForMenu($menu);

        return view('livewire.v3.nutrition.menus.form', [
            ...$this->shellData($unit), 'menu' => $menu,
            'categoryOptions' => BeneficiaryCategory::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'ingredientOptions' => Ingredient::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)
                ->with('portionStandards')->orderBy('name')->get()->mapWithKeys(fn ($item) => [$item->id => [
                    'name' => $item->name, 'has_standard' => $item->portionStandards->where('is_active', true)->isNotEmpty(),
                ]]),
            'unitOptions' => MeasurementUnit::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($item) => [$item->id => trim($item->name.($item->symbol ? " ({$item->symbol})" : ''))]),
            'componentOptions' => MenuComponentType::options(), 'audienceOptions' => MenuAudience::options(),
            'editable' => $menu->isEditable() && $this->allowed('menus.update'), 'activeRevision' => $activeRevision,
        ])->layout('layouts.v3', ['title' => 'Editor Resep Menu']);
    }

    private function persist(): Menu
    {
        abort_unless($this->allowed('menus.update'), 403);
        $menu = $this->menu();
        abort_unless($menu->isEditable(), 403);
        $data = $this->validate([
            'code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'plannedPortions' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:3000'], 'categories' => ['array'], 'categories.*.beneficiary_category_id' => ['required', 'integer'],
            'categories.*.portion_multiplier' => ['required', 'numeric', 'min:0.0001'], 'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'], 'items.*.item_type' => ['required', 'string'], 'items.*.menu_audience' => ['required', 'string'],
            'items.*.portion_weight_small_grams' => ['nullable', 'numeric', 'min:0'], 'items.*.portion_weight_large_grams' => ['nullable', 'numeric', 'min:0'],
            'items.*.portion_weight_toddler_grams' => ['nullable', 'numeric', 'min:0'], 'items.*.portion_weight_maternal_grams' => ['nullable', 'numeric', 'min:0'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'], 'items.*.preparation_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.ingredients' => ['required', 'array', 'min:1'], 'items.*.ingredients.*.ingredient_id' => ['required', 'integer'],
            'items.*.ingredients.*.measurement_unit_id' => ['required', 'integer'], 'items.*.ingredients.*.grams_per_unit_snapshot' => ['nullable', 'numeric', 'min:0.0001'],
            'items.*.ingredients.*.input_quantity_small' => ['required', 'numeric', 'min:0.0001'], 'items.*.ingredients.*.input_quantity_large' => ['required', 'numeric', 'min:0.0001'],
            'items.*.ingredients.*.input_quantity_toddler' => ['nullable', 'numeric', 'min:0'], 'items.*.ingredients.*.input_quantity_maternal' => ['nullable', 'numeric', 'min:0'],
            'items.*.ingredients.*.ingredient_portion_standard_id' => ['nullable', 'integer'],
            'items.*.ingredients.*.portion_source' => ['required', 'in:standard,override,manual'],
            'items.*.ingredients.*.portion_override' => ['boolean'],
            'items.*.ingredients.*.cooking_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'items.*.ingredients.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($menu, $data): Menu {
            $menu->update(['code' => trim($data['code']), 'name' => trim($data['name']), 'planned_portions' => $data['plannedPortions'], 'notes' => trim($data['notes']) ?: null]);
            $categoryIds = [];
            foreach ($data['categories'] as $row) {
                $target = ! empty($row['_id']) ? $menu->categoryTargets()->whereKey($row['_id'])->firstOrFail() : $menu->categoryTargets()->make();
                $target->fill(['beneficiary_category_id' => $row['beneficiary_category_id'], 'portion_multiplier' => $row['portion_multiplier']])->save();
                $categoryIds[] = $target->id;
            }
            $menu->categoryTargets()->when($categoryIds, fn ($q) => $q->whereNotIn('id', $categoryIds))->when(! $categoryIds, fn ($q) => $q)->delete();
            $itemIds = [];
            foreach ($data['items'] as $row) {
                $item = ! empty($row['_id']) ? $menu->items()->whereKey($row['_id'])->firstOrFail() : $menu->items()->make();
                $weights = $this->calculatedWeights($row['ingredients']);
                $item->fill([...collect($row)->only(['name', 'item_type', 'menu_audience', 'sort_order', 'preparation_notes'])->all(),
                    'portion_weight_small_grams' => $weights['small'], 'portion_weight_large_grams' => $weights['large'],
                    'portion_weight_toddler_grams' => $weights['toddler'], 'portion_weight_maternal_grams' => $weights['maternal']])->save();
                $itemIds[] = $item->id;
                $ingredientIds = [];
                foreach ($row['ingredients'] as $ingredientRow) {
                    $ingredient = ! empty($ingredientRow['_id']) ? $item->recipeIngredients()->whereKey($ingredientRow['_id'])->firstOrFail() : $item->recipeIngredients()->make();
                    $ingredient->fill(collect($ingredientRow)->only(['ingredient_id', 'ingredient_portion_standard_id', 'portion_source', 'portion_override', 'measurement_unit_id', 'grams_per_unit_snapshot', 'input_quantity_small', 'input_quantity_large', 'input_quantity_toddler', 'input_quantity_maternal', 'cooking_loss_percent', 'notes'])->all())->save();
                    $ingredientIds[] = $ingredient->id;
                }
                $item->recipeIngredients()->when($ingredientIds, fn ($q) => $q->whereNotIn('id', $ingredientIds))->when(! $ingredientIds, fn ($q) => $q)->delete();
            }
            $menu->items()->when($itemIds, fn ($q) => $q->whereNotIn('id', $itemIds))->when(! $itemIds, fn ($q) => $q)->delete();

            return $menu->refresh();
        });
    }

    private function fillFromMenu(Menu $menu): void
    {
        $menu->load(['categoryTargets', 'items.recipeIngredients']);
        $this->code = $menu->code;
        $this->name = $menu->name;
        $this->plannedPortions = (int) $menu->planned_portions;
        $this->notes = (string) $menu->notes;
        $this->categories = $menu->categoryTargets->map(fn ($row) => ['_id' => $row->id, 'beneficiary_category_id' => $row->beneficiary_category_id, 'portion_multiplier' => (float) $row->portion_multiplier])->all();
        $this->items = $menu->items->map(fn ($item) => ['_id' => $item->id, 'name' => $item->name, 'item_type' => $item->item_type, 'menu_audience' => $item->menu_audience->value,
            'portion_weight_small_grams' => (float) $item->portion_weight_small_grams, 'portion_weight_large_grams' => (float) $item->portion_weight_large_grams,
            'portion_weight_toddler_grams' => (float) $item->portion_weight_toddler_grams, 'portion_weight_maternal_grams' => (float) $item->portion_weight_maternal_grams,
            'sort_order' => (int) $item->sort_order, 'preparation_notes' => (string) $item->preparation_notes,
            'ingredients' => $item->recipeIngredients->map(fn ($row) => ['_id' => $row->id, 'ingredient_id' => $row->ingredient_id, 'measurement_unit_id' => $row->measurement_unit_id,
                'ingredient_portion_standard_id' => $row->ingredient_portion_standard_id, 'portion_source' => $row->portion_source ?: 'manual', 'portion_override' => (bool) $row->portion_override,
                'grams_per_unit_snapshot' => $row->grams_per_unit_snapshot !== null ? (float) $row->grams_per_unit_snapshot : '',
                'input_quantity_small' => (float) $row->inputQuantityFor(MenuPortionProfile::Small), 'input_quantity_large' => (float) $row->inputQuantityFor(MenuPortionProfile::Large),
                'input_quantity_toddler' => (float) $row->inputQuantityFor(MenuPortionProfile::Toddler), 'input_quantity_maternal' => (float) $row->inputQuantityFor(MenuPortionProfile::Maternal),
                'cooking_loss_percent' => (float) $row->cooking_loss_percent, 'notes' => (string) $row->notes])->all(),
        ])->all();
    }

    /** @param array<int, array<string, mixed>> $ingredients */
    private function calculatedWeights(array $ingredients): array
    {
        $weights = ['small' => 0.0, 'large' => 0.0, 'toddler' => 0.0, 'maternal' => 0.0];
        foreach ($ingredients as $row) {
            $factor = max(0.0001, (float) ($row['grams_per_unit_snapshot'] ?? 1));
            $small = (float) ($row['input_quantity_small'] ?? 0);
            $large = (float) ($row['input_quantity_large'] ?? 0);
            $weights['small'] += $small * $factor;
            $weights['large'] += $large * $factor;
            $weights['toddler'] += (float) (($row['input_quantity_toddler'] ?? 0) ?: $small) * $factor;
            $weights['maternal'] += (float) (($row['input_quantity_maternal'] ?? 0) ?: $large) * $factor;
        }

        return array_map(fn (float $value): float => round($value, 4), $weights);
    }

    private function refreshItemWeights(int $itemIndex): void
    {
        $weights = $this->calculatedWeights($this->items[$itemIndex]['ingredients'] ?? []);
        foreach ($weights as $profile => $value) {
            $this->items[$itemIndex]["portion_weight_{$profile}_grams"] = $value;
        }
    }

    private function menu(): Menu
    {
        return Menu::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($this->menuId);
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $e) {
            report($e);
            $this->addError('action', $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage());
        }
    }
}
