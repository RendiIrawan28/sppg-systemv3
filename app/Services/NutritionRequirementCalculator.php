<?php

namespace App\Services;

use App\Models\BeneficiaryCategory;
use App\Models\Menu;
use App\Models\NutritionRequirementPlan;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class NutritionRequirementCalculator
{
    public function __construct(
        private readonly NutritionPortionAllocator $portionAllocator,
        private readonly MenuAllergenRequirementAdjuster $allergenAdjuster,
        private readonly MenuPortionProfileResolver $profileResolver,
        private readonly MenuAudienceMenuResolver $menuResolver,
    ) {}

    public function generate(NutritionRequirementPlan $plan): void
    {
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Rencana kebutuhan yang sudah diajukan tidak dapat dihitung ulang.',
            ]);
        }

        $menu = $plan->menu()
            ->with([
                'items.recipeIngredients.ingredient.measurementUnit',
                'items.recipeIngredients.measurementUnit',
                'categoryTargets.category',
            ])
            ->first();

        if (! $menu) {
            throw ValidationException::withMessages([
                'menu_id' => 'Menu tidak ditemukan.',
            ]);
        }

        if ((int) $menu->sppg_unit_id !== (int) $plan->sppg_unit_id) {
            throw ValidationException::withMessages([
                'menu_id' => 'Menu berasal dari Unit SPPG lain.',
            ]);
        }

        $allocations = array_map(static function (array $allocation): array {
            $actual = max(0, (int) ($allocation['actual_portions'] ?? 0));
            $allocation['portion_multiplier'] = 1;
            $allocation['effective_portions'] = $actual;

            return $allocation;
        }, $this->resolvePortionAllocations($plan, $menu));

        $actualPortions = (int) collect($allocations)->sum('actual_portions');
        $effectivePortions = (float) collect($allocations)->sum('actual_portions');

        if ($actualPortions <= 0 || $effectivePortions <= 0) {
            throw ValidationException::withMessages([
                'total_portions' => 'Jumlah porsi master dan porsi efektif harus lebih dari nol.',
            ]);
        }

        $groups = [];
        $coveredAllocations = [];

        $menuScopes = [[
            'menu' => $menu,
            'allocations' => collect($allocations),
        ]];

        if ($plan->menu_cycle_day_id) {
            $day = $plan->menuCycleDay()
                ->with('variants.menu.items.recipeIngredients.ingredient.measurementUnit')
                ->first();
            $threeBMenu = $day?->effectiveMenuForAudience(
                MenuAudienceMenuResolver::POSYANDU_3B,
            );

            if ($threeBMenu && (int) $threeBMenu->getKey() !== (int) $menu->getKey()) {
                $threeBMenu->loadMissing([
                    'items.recipeIngredients.ingredient.measurementUnit',
                    'items.recipeIngredients.measurementUnit',
                    'categoryTargets.category',
                ]);
                $menuScopes = [
                    [
                        'menu' => $menu,
                        'allocations' => collect($allocations)
                            ->reject(fn (array $allocation): bool => $this->menuResolver
                                ->allocationAudience($allocation) === MenuAudienceMenuResolver::POSYANDU_3B)
                            ->values(),
                    ],
                    [
                        'menu' => $threeBMenu,
                        'allocations' => collect($allocations)
                            ->filter(fn (array $allocation): bool => $this->menuResolver
                                ->allocationAudience($allocation) === MenuAudienceMenuResolver::POSYANDU_3B)
                            ->values(),
                    ],
                ];
            }
        }

        foreach ($menuScopes as $scope) {
            /** @var Menu $effectiveMenu */
            $effectiveMenu = $scope['menu'];
            $scopeAllocations = $scope['allocations'];

            if ($scopeAllocations->isEmpty()) {
                continue;
            }

            foreach ($effectiveMenu->items as $menuItem) {
                $applicableAllocations = $scopeAllocations
                    ->filter(fn (array $allocation): bool => $this->menuItemAppliesToAllocation(
                        menuAudience: $this->enumValue($menuItem->menu_audience ?? 'all', 'all'),
                        portionSize: $this->enumValue($menuItem->portion_size ?? 'all', 'all'),
                        allocation: $allocation,
                    ))
                    ->values();

                if ($applicableAllocations->isEmpty()) {
                    continue;
                }

                foreach ($menuItem->recipeIngredients as $recipeIngredient) {
                    $ingredient = $recipeIngredient->ingredient;

                    if (! $ingredient) {
                        throw ValidationException::withMessages([
                            'items' => "Bahan pada hidangan {$menuItem->name} tidak ditemukan.",
                        ]);
                    }

                    $purchaseUnit = $ingredient->measurementUnit;
                    $unitSnapshot = (string) ($purchaseUnit?->symbol ?: $purchaseUnit?->code ?: 'unit');
                    $purchaseGramsPerUnit = (float) ($ingredient->grams_per_unit ?: $purchaseUnit?->to_base_factor ?: 1);
                    $groupKey = implode('|', [
                        (int) $ingredient->getKey(),
                        strtolower($unitSnapshot),
                        (int) ($ingredient->measurement_unit_id ?? 0),
                    ]);

                    $groups[$groupKey] ??= [
                        'ingredient' => $ingredient,
                        'unit_snapshot' => $unitSnapshot,
                        'grams_per_unit' => max(0.0001, $purchaseGramsPerUnit),
                        'base_quantity' => 0.0,
                        'base_quantity_grams' => 0.0,
                        'allocation_effective_portions' => [],
                        'components' => [],
                        'breakdown' => [],
                    ];

                    foreach ($applicableAllocations as $allocation) {
                        $allocationKey = $this->allocationKey($allocation);
                        $actualPortionsForGroup = max(0, (int) ($allocation['actual_portions'] ?? 0));

                        $category = new BeneficiaryCategory([
                            'code' => (string) ($allocation['code'] ?? ''),
                            'menu_audience' => $this->enumValue($allocation['menu_audience'] ?? ''),
                            'portion_size' => $this->enumValue($allocation['portion_size'] ?? ''),
                        ]);
                        $category->id = (int) ($allocation['beneficiary_category_id'] ?? 0);

                        $profile = $this->profileResolver->profileForCategory($category);
                        $inputPerPortion = $recipeIngredient->inputQuantityFor($profile);
                        $gramsPerPortion = $recipeIngredient->gramsFor($profile);

                        if (! is_finite($inputPerPortion) || $inputPerPortion <= 0) {
                            throw ValidationException::withMessages([
                                'items' => "Jumlah bahan {$ingredient->name} untuk {$profile->label()} pada hidangan {$menuItem->name} belum valid.",
                            ]);
                        }

                        if (! is_finite($gramsPerPortion) || $gramsPerPortion <= 0) {
                            throw ValidationException::withMessages([
                                'items' => "Konversi gram bahan {$ingredient->name} untuk {$profile->label()} pada hidangan {$menuItem->name} belum valid.",
                            ]);
                        }

                        $quantityGrams = $gramsPerPortion * $actualPortionsForGroup;
                        $quantity = $quantityGrams / max(0.0001, $purchaseGramsPerUnit);

                        $coveredAllocations[$allocationKey] = true;
                        $groups[$groupKey]['base_quantity'] += $quantity;
                        $groups[$groupKey]['base_quantity_grams'] += $quantityGrams;
                        $groups[$groupKey]['allocation_effective_portions'][$allocationKey] = $actualPortionsForGroup;
                        $groups[$groupKey]['components'][] = $menuItem->name;

                        $groups[$groupKey]['breakdown'][$allocationKey] ??= [
                            'beneficiary_category_id' => $allocation['beneficiary_category_id'] ?? null,
                            'code' => $allocation['code'] ?? null,
                            'name' => $allocation['name'] ?? 'Kelompok penerima',
                            'menu_audience' => $allocation['menu_audience'] ?? null,
                            'portion_size' => $allocation['portion_size'] ?? null,
                            'portion_profile' => $profile->value,
                            'unit_snapshot' => $unitSnapshot,
                            'quantity_per_portion' => round($gramsPerPortion / max(0.0001, $purchaseGramsPerUnit), 4),
                            'grams_per_portion' => round($gramsPerPortion, 4),
                            'actual_portions' => $actualPortionsForGroup,
                            'portion_multiplier' => 1,
                            'effective_portions' => $actualPortionsForGroup,
                            'quantity' => 0.0,
                            'quantity_grams' => 0.0,
                            'components' => [],
                        ];

                        $groups[$groupKey]['breakdown'][$allocationKey]['quantity'] += $quantity;
                        $groups[$groupKey]['breakdown'][$allocationKey]['quantity_grams'] += $quantityGrams;
                        $groups[$groupKey]['breakdown'][$allocationKey]['components'][] = $menuItem->name;
                    }
                }
            }
        }

        $uncovered = collect($allocations)
            ->reject(fn (array $allocation): bool => isset($coveredAllocations[$this->allocationKey($allocation)]))
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();

        if ($uncovered->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Belum ada komponen menu/resep untuk kelompok: '.$uncovered->implode(', ').'.',
            ]);
        }

        if ($groups === []) {
            throw ValidationException::withMessages([
                'items' => 'Menu belum memiliki bahan resep yang dapat dihitung.',
            ]);
        }

        $buffer = max(0, min(100, (float) $plan->buffer_percent));

        DB::transaction(function () use (
            $plan,
            $groups,
            $buffer,
            $actualPortions,
            $effectivePortions,
            $allocations,
        ): void {
            $locked = NutritionRequirementPlan::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            $locked->items()->delete();

            $totalWeightGrams = 0.0;

            foreach ($groups as $group) {
                $ingredient = $group['ingredient'];
                $baseQuantity = (float) $group['base_quantity'];
                $baseQuantityGrams = (float) $group['base_quantity_grams'];
                $ingredientEffectivePortions = (float) array_sum($group['allocation_effective_portions']);

                $averagePerPortion = $ingredientEffectivePortions > 0
                    ? $baseQuantity / $ingredientEffectivePortions
                    : 0.0;
                $averagePerPortionGrams = $ingredientEffectivePortions > 0
                    ? $baseQuantityGrams / $ingredientEffectivePortions
                    : 0.0;

                // Mengikuti alur workbook: kebutuhan bersih dibagi BDD, dikalikan
                // faktor susut, ditambah buffer, lalu dibulatkan dalam satuan beli.
                $edibleFactor = $ingredient->edibleFactor();
                $lossFactor = $ingredient->effectiveLossFactor();
                $gramsPerUnit = max(0.0001, (float) ($group['grams_per_unit'] ?? 1));
                $grossQuantityGrams = ($baseQuantityGrams / $edibleFactor) * $lossFactor;
                $bufferedQuantityGrams = $grossQuantityGrams * (1 + ($buffer / 100));
                $unroundedQuantity = $bufferedQuantityGrams / $gramsPerUnit;
                $totalQuantity = $ingredient->roundPurchaseQuantity($unroundedQuantity);
                $totalQuantityGrams = $totalQuantity * $gramsPerUnit;
                $estimatedUnitPrice = (float) ($ingredient->reference_price ?? 0);
                $estimatedTotalPrice = $totalQuantity * $estimatedUnitPrice;
                $totalWeightGrams += $totalQuantityGrams;

                $calculationBreakdown = collect($group['breakdown'])
                    ->map(function (array $row): array {
                        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 4);
                        $row['quantity_grams'] = round((float) ($row['quantity_grams'] ?? 0), 4);
                        $row['components'] = implode(
                            ', ',
                            array_values(array_unique($row['components'] ?? []))
                        );

                        return $row;
                    })
                    ->values()
                    ->all();

                $locked->items()->create([
                    'ingredient_id' => $ingredient->getKey(),
                    'ingredient_code_snapshot' => $ingredient->code ?? null,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'unit_snapshot' => $group['unit_snapshot'] ?: 'unit',
                    'quantity_per_portion' => round($averagePerPortion, 4),
                    'quantity_per_portion_grams' => round($averagePerPortionGrams, 4),
                    'effective_portions' => round($ingredientEffectivePortions, 4),
                    'base_quantity' => round($baseQuantity, 4),
                    'base_quantity_grams' => round($baseQuantityGrams, 4),
                    'buffer_percent' => round($buffer, 2),
                    'total_quantity' => round($totalQuantity, 4),
                    'total_quantity_grams' => round($totalQuantityGrams, 4),
                    'total_quantity_kg' => round($totalQuantityGrams / 1000, 4),
                    'edible_portion_percent' => $ingredient->edible_portion_percent ?? null,
                    'loss_factor' => round($lossFactor, 4),
                    'rounding_increment' => $ingredient->rounding_increment,
                    'unrounded_quantity' => round($unroundedQuantity, 4),
                    'estimated_unit_price' => $estimatedUnitPrice ?: null,
                    'estimated_total_price' => $estimatedUnitPrice > 0
                        ? round($estimatedTotalPrice, 2)
                        : null,
                    'recipe_components' => implode(
                        ', ',
                        array_values(array_unique($group['components']))
                    ),
                    'calculation_breakdown' => $calculationBreakdown,
                ]);
            }

            $locked->update([
                'total_portions' => $actualPortions,
                'effective_portions' => round($effectivePortions, 4),
                'portion_breakdown' => array_values($allocations),
                'total_items' => count($groups),
                'total_weight_kg' => round($totalWeightGrams / 1000, 3),
                'generated_at' => now(),
            ]);
        });

        $plan->refresh();
        $this->allergenAdjuster->apply($plan);
        $plan->refresh();
    }

    /** @param array<string, mixed> $allocation */
    private function menuItemAppliesToAllocation(
        string $menuAudience,
        string $portionSize,
        array $allocation,
    ): bool {
        $itemAudience = $this->normalizeDimension($menuAudience);
        $itemPortion = $this->normalizeDimension($portionSize);
        $allocationAudience = $this->normalizeDimension(
            $this->enumValue($allocation['menu_audience'] ?? '')
        );
        $allocationPortion = $this->normalizeDimension(
            $this->enumValue($allocation['portion_size'] ?? '')
        );

        $audienceMatches = in_array($itemAudience, ['', 'all'], true)
            || $itemAudience === $allocationAudience;
        $portionMatches = in_array($itemPortion, ['', 'all'], true)
            || $itemPortion === $allocationPortion;

        return $audienceMatches && $portionMatches;
    }

    /** @param array<string, mixed> $allocation */
    private function allocationKey(array $allocation): string
    {
        $categoryId = (int) ($allocation['beneficiary_category_id'] ?? 0);

        if ($categoryId > 0) {
            return 'id:'.$categoryId;
        }

        return implode('|', [
            $this->normalizeDimension($this->enumValue($allocation['code'] ?? '')),
            $this->normalizeDimension($this->enumValue($allocation['menu_audience'] ?? '')),
            $this->normalizeDimension($this->enumValue($allocation['portion_size'] ?? '')),
        ]);
    }

    private function normalizeDimension(string $value): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $value)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvePortionAllocations(
        NutritionRequirementPlan $plan,
        Menu $menu,
    ): array {
        $targets = $menu->categoryTargets
            ->map(function ($target): array {
                $category = $target->category;

                if (! $category) {
                    throw ValidationException::withMessages([
                        'categoryTargets' => 'Salah satu target kategori menu tidak ditemukan.',
                    ]);
                }

                return [
                    'beneficiary_category_id' => (int) $category->getKey(),
                    'code' => (string) $category->code,
                    'name' => (string) $category->name,
                    'menu_audience' => $this->enumValue($category->menu_audience),
                    'portion_size' => $this->enumValue($category->portion_size),
                    'multiplier' => (float) $target->portion_multiplier,
                ];
            })
            ->values()
            ->all();

        if ($plan->beneficiary_period_id) {
            $period = $plan->beneficiaryPeriod()
                ->with(['destinations.categoryTotals', 'destinations.members'])
                ->first();

            if (! $period || (int) $period->sppg_unit_id !== (int) $plan->sppg_unit_id) {
                throw ValidationException::withMessages([
                    'beneficiary_period_id' => 'Periode penerima tidak ditemukan atau berasal dari Unit SPPG lain.',
                ]);
            }
            if ($targets === []) {
                throw ValidationException::withMessages([
                    'categoryTargets' => 'Menu belum memiliki target kategori penerima dan pengali porsi.',
                ]);
            }

            $groups = $period->destinations
                ->where('is_active', true)
                ->flatMap(function ($destination) {
                    if ($destination->categoryTotals->isNotEmpty()) {
                        return $destination->categoryTotals->map(fn ($total): array => [
                            'beneficiary_category_id' => (int) ($total->beneficiary_category_id ?? 0),
                            'code' => (string) ($total->beneficiary_category_code_snapshot ?? ''),
                            'name' => (string) ($total->beneficiary_category_name_snapshot ?? ''),
                            'menu_audience' => $this->enumValue($total->menu_audience ?? ''),
                            'portion_size' => $this->enumValue($total->portion_category ?? ''),
                            'actual_portions' => (int) $total->total_beneficiaries,
                        ]);
                    }

                    return $destination->members->where('is_active', true)
                        ->groupBy(fn ($member): string => implode('|', [
                            (int) ($member->beneficiary_category_id ?? 0),
                            (string) ($member->beneficiary_category_code_snapshot ?? ''),
                            (string) ($member->menu_audience ?? ''),
                            (string) ($member->portion_category ?? ''),
                        ]))
                        ->map(function ($members): array {
                            $member = $members->first();

                            return [
                                'beneficiary_category_id' => (int) ($member->beneficiary_category_id ?? 0),
                                'code' => (string) ($member->beneficiary_category_code_snapshot ?? ''),
                                'name' => (string) ($member->beneficiary_category_name_snapshot ?? ''),
                                'menu_audience' => $this->enumValue($member->menu_audience ?? ''),
                                'portion_size' => $this->enumValue($member->portion_category ?? ''),
                                'actual_portions' => $members->count(),
                            ];
                        })->values();
                })
                ->groupBy(fn (array $group): string => $group['beneficiary_category_id'] > 0
                    ? 'id:'.$group['beneficiary_category_id']
                    : implode('|', [strtolower($group['code']), strtolower($group['menu_audience']), strtolower($group['portion_size'])]))
                ->map(function ($rows): array {
                    $first = $rows->first();
                    $first['actual_portions'] = (int) $rows->sum('actual_portions');

                    return $first;
                })
                ->values()
                ->all();

            try {
                return array_map(static function (array $allocation): array {
                    $allocation['master_portions'] = $allocation['actual_portions'];

                    return $allocation;
                }, $this->portionAllocator->allocate($groups, $targets));
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['categoryTargets' => $exception->getMessage()]);
            }
        }

        if ($plan->field_distribution_plan_id) {
            $fieldPlan = $plan->fieldDistributionPlan()
                ->with('destinations.recipientGroups')
                ->first();

            if (! $fieldPlan) {
                throw ValidationException::withMessages([
                    'field_distribution_plan_id' => 'Rencana H-3 tidak ditemukan.',
                ]);
            }

            if ((int) $fieldPlan->sppg_unit_id !== (int) $plan->sppg_unit_id) {
                throw ValidationException::withMessages([
                    'field_distribution_plan_id' => 'Rencana H-3 berasal dari Unit SPPG lain.',
                ]);
            }

            if ($targets === []) {
                throw ValidationException::withMessages([
                    'categoryTargets' => 'Menu belum memiliki target kategori penerima dan pengali porsi.',
                ]);
            }

            $groups = $fieldPlan->destinations
                ->flatMap->recipientGroups
                ->map(fn ($group): array => [
                    'beneficiary_category_id' => (int) ($group->beneficiary_category_id ?? 0),
                    'code' => (string) ($group->beneficiary_category_code_snapshot ?? ''),
                    'name' => (string) ($group->beneficiary_category_name_snapshot ?? ''),
                    'menu_audience' => $this->enumValue($group->menu_audience ?? ''),
                    'portion_size' => $this->enumValue($group->portion_size ?? ''),
                    'actual_portions' => (int) ($group->total_portions ?: $group->confirmed_beneficiaries),
                ])
                ->groupBy(function (array $group): string {
                    if ($group['beneficiary_category_id'] > 0) {
                        return 'id:'.$group['beneficiary_category_id'];
                    }

                    return implode('|', [
                        strtolower($group['code']),
                        strtolower($group['menu_audience']),
                        strtolower($group['portion_size']),
                    ]);
                })
                ->map(function ($rows): array {
                    $first = $rows->first();
                    $first['actual_portions'] = (int) $rows->sum('actual_portions');

                    return $first;
                })
                ->values()
                ->all();

            try {
                return $this->portionAllocator->allocate($groups, $targets);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'categoryTargets' => $exception->getMessage(),
                ]);
            }
        }

        if ((int) $plan->total_portions <= 0) {
            throw ValidationException::withMessages([
                'total_portions' => 'Jumlah porsi harus lebih dari nol.',
            ]);
        }

        if (count($targets) > 1) {
            throw ValidationException::withMessages([
                'field_distribution_plan_id' => 'Menu memiliki beberapa kategori penerima. Pilih Rencana H-3 agar jumlah tiap kategori dapat dihitung dengan benar.',
            ]);
        }

        $target = $targets[0] ?? [
            'beneficiary_category_id' => 0,
            'code' => 'umum',
            'name' => 'Porsi umum',
            'menu_audience' => 'general',
            'portion_size' => 'standard',
            'multiplier' => 1,
        ];

        return [[
            'beneficiary_category_id' => $target['beneficiary_category_id'],
            'code' => $target['code'],
            'name' => $target['name'],
            'menu_audience' => $target['menu_audience'],
            'portion_size' => $target['portion_size'],
            'actual_portions' => (int) $plan->total_portions,
            'portion_multiplier' => round((float) $target['multiplier'], 4),
            'effective_portions' => round(
                (int) $plan->total_portions * (float) $target['multiplier'],
                4,
            ),
        ]];
    }

    private function enumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * @return array<int, string>
     */
    public function readinessIssues(NutritionRequirementPlan $plan): array
    {
        $issues = [];

        if ((int) $plan->total_portions <= 0) {
            $issues[] = 'Jumlah porsi master belum valid.';
        }

        if ((float) $plan->effective_portions <= 0) {
            $issues[] = 'Porsi efektif berdasarkan kategori belum dihitung.';
        }

        if (($plan->beneficiary_period_id || $plan->field_distribution_plan_id) && empty($plan->portion_breakdown)) {
            $issues[] = $plan->beneficiary_period_id
                ? 'Rincian kategori dari Master Penerima belum tersedia.'
                : 'Rincian kategori penerima dari data legacy belum tersedia.';
        }

        if ($plan->items()->count() === 0) {
            $issues[] = 'Daftar kebutuhan bahan belum dibuat.';
        }

        if ((float) $plan->total_weight_kg <= 0) {
            $issues[] = 'Total berat kebutuhan bahan belum valid.';
        }

        return $issues;
    }
}
