<?php

namespace App\Livewire\V3\Nutrition;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Ingredient;
use App\Models\IngredientNutrition;
use App\Models\MeasurementUnit;
use App\Models\NutritionComponent;
use App\Services\IngredientNutritionReferenceService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class IngredientNutritions extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $source = '';

    #[Url(history: true)]
    public string $unitFilter = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    public ?int $selectedIngredientId = null;

    public function mount(): void
    {
        abort_unless($this->allowed('nutrition.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function updatedUnitFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function showIngredient(int $ingredientId): void
    {
        abort_unless($this->allowed('nutrition.view'), 403);
        Ingredient::query()->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->where('is_active', true)->findOrFail($ingredientId);
        $this->selectedIngredientId = $ingredientId;
    }

    public function closeIngredient(): void
    {
        $this->selectedIngredientId = null;
    }

    public function render(IngredientNutritionReferenceService $reference)
    {
        abort_unless($this->allowed('nutrition.view'), 403);
        $unit = $this->currentUnit();
        $base = Ingredient::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true);
        $query = (clone $base)->with(['measurementUnit', 'nutritions.component']);
        $search = trim($this->search);
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }
        if ($this->source !== '') {
            $query->where(fn ($q) => $q->where('nutrition_source', $this->source)
                ->orWhereHas('nutritions', fn ($nutrition) => $nutrition->where('source', $this->source)));
        }
        if (ctype_digit($this->unitFilter)) {
            $query->where('measurement_unit_id', (int) $this->unitFilter);
        }
        $reference->filterStatus($query, $this->statusFilter);
        $ingredients = $query->orderBy('name')->orderBy('id')->paginate(20);
        $components = NutritionComponent::query()->orderBy('sort_order')->orderBy('id')->get();
        $primaryComponents = $components->whereIn('code', IngredientNutritionReferenceService::PRIMARY_CODES)->keyBy('code');
        foreach (['energy' => 'Energi', 'protein' => 'Protein', 'fat' => 'Lemak', 'carbohydrate' => 'Karbohidrat', 'fiber' => 'Serat'] as $code => $name) {
            if (! $primaryComponents->has($code)) {
                $primaryComponents->put($code, new NutritionComponent(['code' => $code, 'name' => $name, 'unit' => '']));
            }
        }
        $selected = $this->selectedIngredientId === null ? null : (clone $base)
            ->with(['measurementUnit', 'nutritions.component'])->findOrFail($this->selectedIngredientId);
        $ingredientSources = (clone $base)->whereNotNull('nutrition_source')->where('nutrition_source', '!=', '')
            ->distinct()->pluck('nutrition_source');
        $nutritionSources = IngredientNutrition::query()->whereHas('ingredient', fn ($q) => $q
            ->where('sppg_unit_id', $unit->getKey())->where('is_active', true))
            ->whereNotNull('source')->where('source', '!=', '')->distinct()->pluck('source');

        return view('livewire.v3.nutrition.ingredient-nutritions', [
            ...$this->shellData($unit),
            'ingredients' => $ingredients,
            'primaryComponents' => $primaryComponents,
            'sourceOptions' => $ingredientSources->merge($nutritionSources)->unique()->sort()->values(),
            'unitOptions' => MeasurementUnit::query()->whereHas('ingredients', fn ($q) => $q
                ->where('sppg_unit_id', $unit->getKey())->where('is_active', true))->orderBy('name')->get(),
            'selectedDetail' => $selected === null ? null : $reference->details($selected, $components),
            'reference' => $reference,
        ])->layout('layouts.v3', ['title' => 'Nilai Gizi Bahan']);
    }
}
