<?php

namespace App\Livewire\V3\Nutrition;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\MeasurementUnit;
use App\Models\Ingredient;
use App\Models\IngredientPortionStandard;
use App\Models\NutritionStandard;
use Livewire\Attributes\Url;
use Livewire\Component;

class Standards extends Component
{
    use InteractsWithV3Shell;

    #[Url(history: true)]
    public string $tab = 'nutrition';

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['ingredients', 'units', 'nutrition', 'portions'], true), 404);
        $this->tab = $tab;
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $user = auth()->user();

        abort_unless(
            $user->is_super_admin
                || $user->can('nutrition.view')
                || $user->can('measurement_units.view'),
            403,
        );

        return view('livewire.v3.nutrition.standards', [
            ...$this->shellData($unit),
            'measurementUnits' => MeasurementUnit::query()->orderBy('unit_type')->orderBy('name')->get(),
            'ingredients' => Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->limit(600)->get(),
            'nutritionStandards' => NutritionStandard::query()
                ->with(['category', 'component'])
                ->where('sppg_unit_id', $unit->getKey())
                ->orderByDesc('is_active')
                ->orderBy('beneficiary_category_id')
                ->limit(100)
                ->get(),
            'portionStandards' => IngredientPortionStandard::query()
                ->with(['ingredient', 'measurementUnit'])
                ->where('sppg_unit_id', $unit->getKey())
                ->orderByDesc('is_active')
                ->orderBy('component_type')
                ->orderBy('source_row')
                ->limit(600)
                ->get(),
        ])->layout('layouts.v3', ['title' => 'Standar & Satuan']);
    }
}
