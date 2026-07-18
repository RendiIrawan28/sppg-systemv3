<?php

namespace App\Services;

use App\Enums\MenuStatus;
use App\Models\Menu;
use App\Models\MenuCycleDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuCloneService
{
    public function cloneForRevision(
        Menu $source,
        MenuCycleDay $day,
        User $creator,
        ?string $reason = null,
    ): Menu {
        $revision = max(1, (int) $source->revision_number + 1);

        return DB::transaction(fn (): Menu => $this->cloneMenu($source, $day, $creator, [
            'code' => $this->uniqueCode(
                unitId: (int) $source->sppg_unit_id,
                base: "{$source->code}-R{$revision}-D{$day->day_number}",
            ),
            'status' => MenuStatus::RevisionRequired->value,
            'revision_number' => $revision,
            'review_notes' => $reason,
            'source_menu_id' => $source->source_menu_id ?: $source->getKey(),
            'snapshot_cycle_day_id' => $day->getKey(),
            'is_cycle_snapshot' => true,
            'snapshot_version' => max(1, (int) $day->snapshot_version + 1),
            'snapshot_created_at' => now(),
            'refresh_nutrition' => true,
        ]));
    }

    public function cloneAsIndependentDraft(
        Menu $source,
        MenuCycleDay $day,
        User $creator,
    ): Menu {
        return DB::transaction(fn (): Menu => $this->cloneMenu($source, $day, $creator, [
            'code' => $this->uniqueCode(
                unitId: (int) $source->sppg_unit_id,
                base: "{$source->code}-COPY-D{$day->day_number}",
            ),
            'status' => MenuStatus::Draft->value,
            'revision_number' => 0,
            'review_notes' => null,
            'source_menu_id' => $source->source_menu_id ?: $source->getKey(),
            'snapshot_cycle_day_id' => null,
            'is_cycle_snapshot' => false,
            'snapshot_version' => 0,
            'snapshot_created_at' => null,
            'refresh_nutrition' => false,
        ]));
    }

    public function cloneForCycleSnapshot(
        Menu $source,
        MenuCycleDay $day,
        User $creator,
        int $version,
        int $plannedPortions,
    ): Menu {
        $baseSourceId = (int) ($source->source_menu_id ?: $source->getKey());

        return DB::transaction(fn (): Menu => $this->cloneMenu($source, $day, $creator, [
            'code' => $this->uniqueCode(
                unitId: (int) $source->sppg_unit_id,
                base: sprintf('%s-S%02d-D%02d', $source->code, $version, $day->day_number),
            ),
            'status' => MenuStatus::PendingReview->value,
            'revision_number' => max((int) $source->revision_number, $version - 1),
            'review_notes' => null,
            'source_menu_id' => $baseSourceId,
            'snapshot_cycle_day_id' => $day->getKey(),
            'is_cycle_snapshot' => true,
            'snapshot_version' => $version,
            'snapshot_created_at' => now(),
            'planned_portions' => $plannedPortions,
            'refresh_nutrition' => true,
        ]));
    }

    /** @param array<string, mixed> $overrides */
    private function cloneMenu(
        Menu $source,
        MenuCycleDay $day,
        User $creator,
        array $overrides,
    ): Menu {
        $source->loadMissing([
            'categoryTargets',
            'items.recipeIngredients',
            'allergenSubstitutions.ingredients',
        ]);

        $clone = $source->replicate();
        $clone->forceFill([
            'code' => $overrides['code'],
            'name' => $source->name,
            'service_date' => $day->service_date,
            'meal_type' => 'lunch',
            'planned_portions' => $overrides['planned_portions'] ?? $source->planned_portions,
            'status' => $overrides['status'],
            'revision_number' => $overrides['revision_number'],
            'source_menu_id' => $overrides['source_menu_id'] ?? null,
            'snapshot_cycle_day_id' => $overrides['snapshot_cycle_day_id'] ?? null,
            'is_cycle_snapshot' => $overrides['is_cycle_snapshot'] ?? false,
            'snapshot_version' => $overrides['snapshot_version'] ?? 0,
            'snapshot_created_at' => $overrides['snapshot_created_at'] ?? null,
            'snapshot_payload' => null,
            'created_by' => $creator->getKey(),
            'submitted_by' => null,
            'approved_by' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'last_revision_submitted_at' => null,
            'last_revision_approved_at' => null,
            'review_notes' => $overrides['review_notes'],
        ]);
        $clone->save();

        foreach ($source->categoryTargets as $target) {
            $clone->categoryTargets()->create([
                'beneficiary_category_id' => $target->beneficiary_category_id,
                'portion_multiplier' => $target->portion_multiplier,
            ]);
        }

        $itemMap = [];

        foreach ($source->items as $item) {
            $newItem = $clone->items()->create([
                'name' => $item->name,
                'item_type' => $item->item_type,
                'menu_audience' => $item->getRawOriginal('menu_audience') ?? 'all',
                'portion_size' => $item->portion_size,
                'portion_weight_grams' => $item->portion_weight_grams,
                'portion_weight_small_grams' => $item->portion_weight_small_grams,
                'portion_weight_large_grams' => $item->portion_weight_large_grams,
                'portion_weight_toddler_grams' => $item->portion_weight_toddler_grams,
                'portion_weight_maternal_grams' => $item->portion_weight_maternal_grams,
                'sort_order' => $item->sort_order,
                'preparation_notes' => $item->preparation_notes,
            ]);

            $itemMap[(int) $item->getKey()] = (int) $newItem->getKey();

            foreach ($item->recipeIngredients as $recipe) {
                $newItem->recipeIngredients()->create([
                    'ingredient_id' => $recipe->ingredient_id,
                    'measurement_unit_id' => $recipe->measurement_unit_id,
                    'input_unit_code_snapshot' => $recipe->input_unit_code_snapshot,
                    'input_unit_name_snapshot' => $recipe->input_unit_name_snapshot,
                    'grams_per_unit_snapshot' => $recipe->grams_per_unit_snapshot,
                    'input_quantity_small' => $recipe->input_quantity_small,
                    'input_quantity_large' => $recipe->input_quantity_large,
                    'input_quantity_toddler' => $recipe->input_quantity_toddler,
                    'input_quantity_maternal' => $recipe->input_quantity_maternal,
                    'quantity' => $recipe->quantity,
                    'quantity_grams' => $recipe->quantity_grams,
                    'quantity_small_grams' => $recipe->quantity_small_grams,
                    'quantity_large_grams' => $recipe->quantity_large_grams,
                    'quantity_toddler_grams' => $recipe->quantity_toddler_grams,
                    'quantity_maternal_grams' => $recipe->quantity_maternal_grams,
                    'cooking_loss_percent' => $recipe->cooking_loss_percent,
                    'notes' => $recipe->notes,
                ]);
            }
        }

        if (Schema::hasTable('menu_allergen_substitutions')) {
            foreach ($source->allergenSubstitutions as $substitution) {
                $newItemId = $itemMap[(int) $substitution->original_menu_item_id] ?? null;

                if (! $newItemId) {
                    continue;
                }

                $newSubstitution = $clone->allergenSubstitutions()->create([
                    'sppg_unit_id' => $clone->sppg_unit_id,
                    'allergen_id' => $substitution->allergen_id,
                    'original_menu_item_id' => $newItemId,
                    'replacement_name' => $substitution->replacement_name,
                    'menu_audience' => $substitution->getRawOriginal('menu_audience') ?? 'all',
                    'affected_portions_override' => $substitution->affected_portions_override,
                    'affected_portion_profile_override' => $substitution->affected_portion_profile_override,
                    'notes' => $substitution->notes,
                    'is_active' => $substitution->is_active,
                    'created_by' => $creator->getKey(),
                ]);

                foreach ($substitution->ingredients as $ingredient) {
                    $newSubstitution->ingredients()->create([
                        'ingredient_id' => $ingredient->ingredient_id,
                        'quantity_small_grams' => $ingredient->quantity_small_grams,
                        'quantity_large_grams' => $ingredient->quantity_large_grams,
                        'quantity_toddler_grams' => $ingredient->quantity_toddler_grams,
                        'quantity_maternal_grams' => $ingredient->quantity_maternal_grams,
                        'notes' => $ingredient->notes,
                    ]);
                }
            }
        }

        $clone->loadMissing('categoryTargets.category', 'items.recipeIngredients.ingredient');
        $clone->updateQuietly(['snapshot_payload' => $this->snapshotPayload($clone)]);

        if (($overrides['refresh_nutrition'] ?? false) === true) {
            app(MenuNutritionCalculator::class)->refresh($clone);
        }

        return $clone->refresh();
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(Menu $menu): array
    {
        return [
            'menu' => [
                'id' => $menu->getKey(),
                'source_menu_id' => $menu->source_menu_id,
                'code' => $menu->code,
                'name' => $menu->name,
                'service_date' => $menu->service_date?->toDateString(),
                'meal_type' => 'lunch',
                'planned_portions' => $menu->planned_portions,
                'snapshot_version' => $menu->snapshot_version,
            ],
            'targets' => $menu->categoryTargets->map(fn ($target): array => [
                'beneficiary_category_id' => $target->beneficiary_category_id,
                'category' => $target->category?->name,
                'portion_multiplier' => $target->portion_multiplier,
            ])->values()->all(),
            'items' => $menu->items->map(fn ($item): array => [
                'name' => $item->name,
                'type' => $item->item_type,
                'audience' => $item->getRawOriginal('menu_audience') ?? 'all',
                'portion_weights' => [
                    'small' => $item->portion_weight_small_grams,
                    'large' => $item->portion_weight_large_grams,
                    'toddler' => $item->portion_weight_toddler_grams,
                    'maternal' => $item->portion_weight_maternal_grams,
                ],
                'ingredients' => $item->recipeIngredients->map(fn ($recipe): array => [
                    'ingredient_id' => $recipe->ingredient_id,
                    'ingredient' => $recipe->ingredient?->name,
                    'measurement_unit_id' => $recipe->measurement_unit_id,
                    'input_unit' => [
                        'code' => $recipe->input_unit_code_snapshot,
                        'name' => $recipe->input_unit_name_snapshot,
                        'grams_per_unit' => $recipe->grams_per_unit_snapshot,
                    ],
                    'input_quantities' => [
                        'small' => $recipe->input_quantity_small,
                        'large' => $recipe->input_quantity_large,
                        'toddler' => $recipe->input_quantity_toddler,
                        'maternal' => $recipe->input_quantity_maternal,
                    ],
                    'grams' => [
                        'small' => $recipe->quantity_small_grams,
                        'large' => $recipe->quantity_large_grams,
                        'toddler' => $recipe->quantity_toddler_grams,
                        'maternal' => $recipe->quantity_maternal_grams,
                    ],
                    'cooking_loss_percent' => $recipe->cooking_loss_percent,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function uniqueCode(int $unitId, string $base): string
    {
        $base = mb_substr(trim($base), 0, 44);
        $candidate = $base;
        $counter = 2;

        while (Menu::query()
            ->where('sppg_unit_id', $unitId)
            ->where('code', $candidate)
            ->exists()) {
            $suffix = "-{$counter}";
            $candidate = mb_substr($base, 0, 50 - mb_strlen($suffix)).$suffix;
            $counter++;
        }

        return $candidate;
    }
}
