<?php

namespace App\Services;

use App\Enums\MenuPortionProfile;
use App\Models\BeneficiaryCategory;
use App\Models\MenuAllergenSubstitution;
use App\Models\NutritionRequirementItem;
use App\Models\NutritionRequirementPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuAllergenRequirementAdjuster
{
    public function __construct(
        private readonly MenuPortionProfileResolver $profileResolver,
    ) {
    }

    public function apply(NutritionRequirementPlan $plan): void
    {
        $substitutions = MenuAllergenSubstitution::query()
            ->with([
                'originalMenuItem.recipeIngredients.ingredient',
                'ingredients.ingredient',
            ])
            ->where('menu_id', $plan->menu_id)
            ->where('sppg_unit_id', $plan->sppg_unit_id)
            ->where('is_active', true)
            ->get();

        if ($substitutions->isEmpty()) {
            return;
        }

        foreach ($substitutions as $substitution) {
            $counts = $this->profileCounts($plan, $substitution);

            if ($counts->sum() <= 0) {
                continue;
            }

            foreach ($substitution->originalMenuItem?->recipeIngredients ?? [] as $originalIngredient) {
                foreach (MenuPortionProfile::cases() as $profile) {
                    $count = (int) ($counts[$profile->value] ?? 0);
                    $grams = $originalIngredient->gramsFor($profile) * $count;

                    if ($grams > 0) {
                        $this->adjustItem(
                            plan: $plan,
                            ingredientId: (int) $originalIngredient->ingredient_id,
                            gramsDelta: -$grams,
                            componentLabel: "Pengurangan alergi: {$substitution->originalMenuItem->name}",
                        );
                    }
                }
            }

            foreach ($substitution->ingredients as $replacementIngredient) {
                foreach (MenuPortionProfile::cases() as $profile) {
                    $count = (int) ($counts[$profile->value] ?? 0);
                    $grams = $replacementIngredient->gramsFor($profile) * $count;

                    if ($grams > 0) {
                        $this->adjustItem(
                            plan: $plan,
                            ingredientId: (int) $replacementIngredient->ingredient_id,
                            gramsDelta: $grams,
                            componentLabel: "Pengganti alergi: {$substitution->replacement_name}",
                        );
                    }
                }
            }
        }

        $plan->refresh();
        $plan->update([
            'total_items' => $plan->items()->where('total_quantity_grams', '>', 0)->count(),
            'total_weight_kg' => round((float) $plan->items()->sum('total_quantity_grams') / 1000, 3),
        ]);
    }

    /** @return Collection<string, int> */
    private function profileCounts(NutritionRequirementPlan $plan, MenuAllergenSubstitution $substitution): Collection
    {
        if ($substitution->affected_portions_override !== null) {
            $profile = MenuPortionProfile::tryFrom(
                (string) $substitution->affected_portion_profile_override
            ) ?? match ((string) ($substitution->menu_audience?->value ?? $substitution->menu_audience)) {
                'toddler' => MenuPortionProfile::Toddler,
                'maternal' => MenuPortionProfile::Maternal,
                default => MenuPortionProfile::Large,
            };

            return collect([$profile->value => (int) $substitution->affected_portions_override]);
        }

        if (
            ! $plan->field_distribution_plan_id
            || ! Schema::hasTable('field_distribution_plans')
            || ! Schema::hasTable('beneficiary_period_members')
            || ! Schema::hasTable('beneficiary_allergen')
        ) {
            return collect();
        }

        $periodId = DB::table('field_distribution_plans')
            ->where('id', $plan->field_distribution_plan_id)
            ->value('beneficiary_period_id');

        if (! $periodId) {
            return collect();
        }

        $rows = DB::table('beneficiary_period_members as bpm')
            ->join('beneficiary_allergen as ba', function ($join): void {
                $join->on('ba.beneficiary_id', '=', 'bpm.source_beneficiary_id')
                    ->where('ba.is_active', true);
            })
            ->leftJoin('beneficiary_categories as bc', 'bc.id', '=', 'bpm.beneficiary_category_id')
            ->where('bpm.beneficiary_period_id', $periodId)
            ->where('bpm.is_active', true)
            ->where('ba.allergen_id', $substitution->allergen_id)
            ->select(['bc.id', 'bc.code', 'bc.menu_audience', 'bc.portion_size'])
            ->get();

        $allergyCounts = $rows
            ->map(function ($row): string {
                $category = new BeneficiaryCategory([
                    'code' => $row->code,
                    'menu_audience' => $row->menu_audience,
                    'portion_size' => $row->portion_size,
                ]);
                $category->id = $row->id;

                return $this->profileResolver->profileForCategory($category)->value;
            })
            ->countBy();

        // Jika jumlah aktual distribusi lebih kecil daripada master periode,
        // jumlah menu alergi tidak boleh melebihi porsi aktual profil tersebut.
        $actualCaps = collect($plan->portion_breakdown ?? [])
            ->groupBy(function (array $row): string {
                $category = new BeneficiaryCategory([
                    'code' => (string) ($row['code'] ?? ''),
                    'menu_audience' => (string) ($row['menu_audience'] ?? ''),
                    'portion_size' => (string) ($row['portion_size'] ?? ''),
                ]);
                $category->id = (int) ($row['beneficiary_category_id'] ?? 0);

                return $this->profileResolver->profileForCategory($category)->value;
            })
            ->map(fn ($rows): int => (int) collect($rows)->sum('actual_portions'));

        $audience = (string) ($substitution->menu_audience?->value ?? $substitution->menu_audience ?? 'all');
        $allowedProfiles = match ($audience) {
            'student' => [MenuPortionProfile::Small->value, MenuPortionProfile::Large->value],
            'toddler' => [MenuPortionProfile::Toddler->value],
            'maternal' => [MenuPortionProfile::Maternal->value],
            default => array_map(fn (MenuPortionProfile $profile): string => $profile->value, MenuPortionProfile::cases()),
        };

        return $allergyCounts
            ->filter(fn (int $count, string $profile): bool => in_array($profile, $allowedProfiles, true))
            ->map(
            fn (int $count, string $profile): int => min(
                $count,
                (int) ($actualCaps[$profile] ?? $count),
            )
        );
    }

    private function adjustItem(
        NutritionRequirementPlan $plan,
        int $ingredientId,
        float $gramsDelta,
        string $componentLabel,
    ): void {
        $item = $plan->items()->where('ingredient_id', $ingredientId)->first();
        $bufferFactor = 1 + ((float) $plan->buffer_percent / 100);

        if (! $item && $gramsDelta > 0) {
            $ingredient = \App\Models\Ingredient::query()->find($ingredientId);

            if (! $ingredient) {
                return;
            }

            $item = $plan->items()->create([
                'ingredient_id' => $ingredientId,
                'ingredient_code_snapshot' => $ingredient->code,
                'ingredient_name_snapshot' => $ingredient->name,
                'unit_snapshot' => 'gram',
                'quantity_per_portion_grams' => 0,
                'effective_portions' => 0,
                'base_quantity_grams' => 0,
                'buffer_percent' => $plan->buffer_percent,
                'total_quantity_grams' => 0,
                'total_quantity_kg' => 0,
                'edible_portion_percent' => $ingredient->edible_portion_percent,
                'recipe_components' => $componentLabel,
                'calculation_breakdown' => [],
            ]);
        }

        if (! $item) {
            return;
        }

        $newBase = max(0, (float) $item->base_quantity_grams + $gramsDelta);
        $newTotal = $newBase * $bufferFactor;
        $components = collect(explode(', ', (string) $item->recipe_components))
            ->push($componentLabel)
            ->filter()
            ->unique()
            ->implode(', ');
        $breakdown = collect($item->calculation_breakdown ?? [])
            ->push([
                'type' => 'allergen_adjustment',
                'name' => $componentLabel,
                'quantity_grams' => round($gramsDelta, 4),
                'components' => $componentLabel,
            ])
            ->values()
            ->all();

        $item->update([
            'base_quantity_grams' => round($newBase, 4),
            'total_quantity_grams' => round($newTotal, 4),
            'total_quantity_kg' => round($newTotal / 1000, 4),
            'recipe_components' => $components,
            'calculation_breakdown' => $breakdown,
        ]);
    }
}
