<?php

namespace App\Services;

use App\Enums\MenuAudience;
use App\Models\Menu;
use App\Models\NutritionComponent;
use App\Models\NutritionStandard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuNutritionCalculator
{
    public function __construct(
        private readonly MenuAllergenAnalyzer $allergenAnalyzer,
        private readonly MenuPortionProfileResolver $profileResolver,
    ) {}

    public function refresh(Menu $menu): void
    {
        DB::transaction(function () use ($menu): void {
            $menu = Menu::query()
                ->with([
                    'items.recipeIngredients.ingredient.nutritions',
                    'categoryTargets.category',
                ])
                ->lockForUpdate()
                ->findOrFail($menu->getKey());

            $menu->nutritionSummaries()->delete();
            $components = NutritionComponent::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            foreach ($menu->categoryTargets as $target) {
                $category = $target->category;

                if (! $category) {
                    continue;
                }

                $profile = $this->profileResolver->profileForCategory($category);
                $audience = $this->profileResolver->audienceForCategory($category);
                $totals = [];

                foreach ($menu->items as $item) {
                    $itemAudience = $item->menu_audience instanceof MenuAudience
                        ? $item->menu_audience
                        : MenuAudience::tryFrom((string) $item->menu_audience) ?? MenuAudience::All;

                    if (! in_array($itemAudience, [MenuAudience::All, $audience], true)) {
                        continue;
                    }

                    foreach ($item->recipeIngredients as $recipeIngredient) {
                        $ingredient = $recipeIngredient->ingredient;
                        $grams = $recipeIngredient->gramsFor($profile);

                        if (! $ingredient || $grams <= 0) {
                            throw ValidationException::withMessages([
                                'recipeIngredients' => "Berat bahan pada hidangan {$item->name} untuk {$profile->label()} wajib lebih dari 0 gram.",
                            ]);
                        }

                        // Nilai ini berupa gram untuk bahan berbobot dan jumlah satuan
                        // untuk bahan pcs. Basis pembaginya mengikuti DAFTAR BAHAN:
                        // 100 untuk gram/kg dan 1 untuk SATUAN/pcs.
                        $effectiveGrams = $grams;

                        foreach ($ingredient->nutritions as $nutrition) {
                            $componentId = (int) $nutrition->nutrition_component_id;
                            $totals[$componentId] = ($totals[$componentId] ?? 0)
                                + (((float) $nutrition->value_per_100g * $effectiveGrams)
                                    / max(0.0001, (float) ($ingredient->nutrition_reference_grams ?: 100)));
                        }
                    }
                }

                foreach ($components as $component) {
                    $componentId = (int) $component->getKey();
                    $value = (float) ($totals[$componentId] ?? 0);
                    $standard = NutritionStandard::query()
                        ->where('sppg_unit_id', $menu->sppg_unit_id)
                        ->where('beneficiary_category_id', $category->getKey())
                        ->where('nutrition_component_id', $componentId)
                        ->where('is_active', true)
                        ->whereDate('effective_from', '<=', $menu->service_date?->toDateString() ?? now()->toDateString())
                        ->where(fn ($query) => $query
                            ->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>=', $menu->service_date?->toDateString() ?? now()->toDateString()))
                        ->latest('effective_from')
                        ->first();

                    $targetValue = $standard?->target_value !== null
                        ? (float) $standard->target_value
                        : null;
                    $achievement = $targetValue && $targetValue > 0
                        ? ($value / $targetValue) * 100
                        : null;

                    $menu->nutritionSummaries()->create([
                        'beneficiary_category_id' => $category->getKey(),
                        'nutrition_component_id' => $componentId,
                        'value_per_portion' => round($value, 4),
                        'standard_target' => $targetValue !== null ? round($targetValue, 4) : null,
                        'achievement_percent' => $achievement !== null ? round($achievement, 2) : null,
                        'calculated_at' => now(),
                    ]);
                }
            }

            $this->allergenAnalyzer->refresh($menu);
        });
    }
}
