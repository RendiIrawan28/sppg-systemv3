<?php

namespace App\Services;

use App\Models\Menu;

class MenuAllergenAnalyzer
{
    public function refresh(Menu $menu): void
    {
        $menu->load([
            'items.recipeIngredients.ingredient.allergenLinks.allergen',
        ]);

        $summaries = [];

        foreach ($menu->items as $menuItem) {
            foreach ($menuItem->recipeIngredients as $recipeIngredient) {
                $ingredient = $recipeIngredient->ingredient;

                if (! $ingredient) {
                    continue;
                }

                foreach ($ingredient->allergenLinks as $link) {
                    $allergen = $link->allergen;

                    if (! $allergen || ! $allergen->is_active) {
                        continue;
                    }

                    $allergenId = (int) $allergen->getKey();

                    $summaries[$allergenId] ??= [
                        'allergen_id' => $allergenId,
                        'has_cross_contamination_risk' => false,
                        'ingredients' => [],
                    ];

                    $summaries[$allergenId]['has_cross_contamination_risk'] =
                        $summaries[$allergenId]['has_cross_contamination_risk']
                        || (bool) $link->contamination_risk;

                    $summaries[$allergenId]['ingredients'][(int) $ingredient->getKey()] = [
                        'id' => (int) $ingredient->getKey(),
                        'code' => $ingredient->code,
                        'name' => $ingredient->name,
                        'menu_item' => $menuItem->name,
                        'contamination_risk' => (bool) $link->contamination_risk,
                    ];
                }
            }
        }

        $menu->allergenSummaries()->delete();

        foreach ($summaries as $summary) {
            $ingredients = array_values($summary['ingredients']);

            $menu->allergenSummaries()->create([
                'allergen_id' => $summary['allergen_id'],
                'source_ingredient_count' => count($ingredients),
                'has_cross_contamination_risk' =>
                    $summary['has_cross_contamination_risk'],
                'source_ingredients' => $ingredients,
                'calculated_at' => now(),
            ]);
        }
    }
}
