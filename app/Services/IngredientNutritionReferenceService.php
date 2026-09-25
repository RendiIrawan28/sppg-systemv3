<?php

namespace App\Services;

use App\Enums\MenuPortionProfile;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\NutritionComponent;
use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IngredientNutritionReferenceService
{
    public const PRIMARY_CODES = ['energy', 'protein', 'fat', 'carbohydrate', 'fiber'];

    public function basisLabel(Ingredient $ingredient): string
    {
        $basis = (float) ($ingredient->nutrition_reference_grams ?? 0);
        if ($basis <= 0) {
            return 'Basis belum diisi';
        }

        if ($basis === 1.0 && $ingredient->measurementUnit?->unit_type === 'count') {
            $unit = $ingredient->measurementUnit->symbol ?: $ingredient->measurementUnit->code ?: 'satuan';

            return "per 1 {$unit}";
        }

        return 'per '.rtrim(rtrim(number_format($basis, 4, ',', '.'), '0'), ',').' g';
    }

    /** @return array<int, string> */
    public function statusFlags(Ingredient $ingredient): array
    {
        $available = $ingredient->nutritions->filter(fn ($row) => $row->value_per_100g !== null)
            ->map(fn ($row) => strtolower((string) $row->component?->code))->all();
        $flags = [];
        if (array_diff(self::PRIMARY_CODES, $available) !== []) {
            $flags[] = 'Belum lengkap';
        }
        if ($this->basisNeedsReview($ingredient)) {
            $flags[] = 'Periksa basis';
        }

        return $flags ?: ['Lengkap'];
    }

    /** Aggregate duplicate component rows exactly as the menu calculator sums them. */
    public function primaryNutritionRows(Ingredient $ingredient): Collection
    {
        return $ingredient->nutritions->groupBy(fn ($row) => strtolower((string) $row->component?->code))
            ->map(fn (Collection $rows) => (object) ['value_per_100g' => $rows->contains(fn ($row) => $row->value_per_100g !== null)
                ? $rows->sum(fn ($row) => (float) $row->value_per_100g) : null]);
    }

    public function basisNeedsReview(Ingredient $ingredient): bool
    {
        $basis = (float) ($ingredient->nutrition_reference_grams ?? 0);
        $type = $ingredient->measurementUnit?->unit_type;

        return $basis <= 0 || $type === null
            || ($type === 'count' && $basis !== 1.0)
            || ($type === 'weight' && $basis !== 100.0);
    }

    public function filterStatus(Builder $query, string $status): Builder
    {
        if ($status === 'incomplete') {
            return $query->where(function (Builder $query): void {
                foreach (self::PRIMARY_CODES as $code) {
                    $query->orWhereDoesntHave('nutritions', fn (Builder $nutrition) => $nutrition
                        ->whereNotNull('value_per_100g')->whereHas('component', fn (Builder $component) => $component->where('code', $code)));
                }
            });
        }

        if ($status === 'check_basis') {
            return $query->where(function (Builder $query): void {
                $query->whereNull('nutrition_reference_grams')->orWhere('nutrition_reference_grams', '<=', 0)
                    ->orWhereDoesntHave('measurementUnit')
                    ->orWhere(fn (Builder $q) => $q->whereHas('measurementUnit', fn (Builder $unit) => $unit->where('unit_type', 'count'))
                        ->where('nutrition_reference_grams', '!=', 1))
                    ->orWhere(fn (Builder $q) => $q->whereHas('measurementUnit', fn (Builder $unit) => $unit->where('unit_type', 'weight'))
                        ->where('nutrition_reference_grams', '!=', 100));
            });
        }

        if ($status === 'complete') {
            $query->where('nutrition_reference_grams', '>', 0)->whereHas('measurementUnit')
                ->where(fn (Builder $q) => $q->whereDoesntHave('measurementUnit', fn (Builder $unit) => $unit->where('unit_type', 'count'))
                    ->orWhere('nutrition_reference_grams', 1))
                ->where(fn (Builder $q) => $q->whereDoesntHave('measurementUnit', fn (Builder $unit) => $unit->where('unit_type', 'weight'))
                    ->orWhere('nutrition_reference_grams', 100));
            foreach (self::PRIMARY_CODES as $code) {
                $query->whereHas('nutritions', fn (Builder $nutrition) => $nutrition
                    ->whereNotNull('value_per_100g')->whereHas('component', fn (Builder $component) => $component->where('code', $code)));
            }
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function details(Ingredient $ingredient, Collection $components, ?array $draftRow = null, ?MeasurementUnit $draftUnit = null): array
    {
        $ingredient->loadMissing(['measurementUnit', 'nutritions.component']);
        $values = $ingredient->nutritions->groupBy('nutrition_component_id');
        $recipe = $draftRow === null ? null : $this->draftRecipe($ingredient, $draftRow, $draftUnit);
        $profiles = $recipe === null ? [] : collect(MenuPortionProfile::cases())->mapWithKeys(function (MenuPortionProfile $profile) use ($recipe, $draftRow) {
            $grams = $recipe->gramsFor($profile);
            $fallback = match ($profile) {
                MenuPortionProfile::Toddler => 'Porsi Kecil',
                MenuPortionProfile::Maternal => 'Porsi Besar',
                default => null,
            };
            $hasSpecificInput = is_numeric($draftRow['input_quantity_'.$profile->value] ?? null)
                && (float) $draftRow['input_quantity_'.$profile->value] > 0;

            return [$profile->value => [
                'label' => $profile->label(),
                'quantity' => $grams > 0 ? $grams : null,
                'fallback' => $grams > 0 && ! $hasSpecificInput ? $fallback : null,
            ]];
        })->all();

        $rows = $components->map(function (NutritionComponent $component) use ($ingredient, $values, $profiles) {
            $nutritions = $values->get($component->id, collect());
            $reference = $nutritions->contains(fn ($row) => $row->value_per_100g !== null)
                ? $nutritions->sum(fn ($row) => (float) $row->value_per_100g) : null;

            return [
                'code' => $component->code, 'name' => $component->name, 'unit' => $component->unit,
                'reference' => $reference, 'source' => $nutritions->pluck('source')->filter()->unique()->implode(', '),
                'contributions' => collect($profiles)->mapWithKeys(fn ($profile, $key) => [$key => $reference !== null && $profile['quantity'] !== null
                    ? $nutritions->sum(fn ($row) => MenuNutritionCalculator::ingredientContribution($ingredient, $row, $profile['quantity'])) : null])->all(),
            ];
        })->all();

        return [
            'name' => $ingredient->name, 'code' => $ingredient->code,
            'unit' => $ingredient->measurementUnit?->name ?? 'Belum diisi',
            'grams_per_unit' => $ingredient->grams_per_unit,
            'basis' => $this->basisLabel($ingredient),
            'basis_fallback' => (float) ($ingredient->nutrition_reference_grams ?? 0) <= 0,
            'source' => $ingredient->nutrition_source ?: ($ingredient->nutritions->pluck('source')->filter()->unique()->implode(', ') ?: '-'),
            'has_nutrition' => $ingredient->nutritions->contains(fn ($row) => $row->value_per_100g !== null),
            'status' => $this->statusFlags($ingredient), 'components' => $rows,
            'profiles' => $profiles,
            'conversion_warning' => $recipe !== null && $ingredient->measurementUnit?->unit_type === 'count'
                && (float) $ingredient->nutrition_reference_grams === 1.0
                && ($recipe->effectiveGramsPerUnit() !== 1.0 || $draftUnit?->unit_type !== 'count'),
        ];
    }

    private function draftRecipe(Ingredient $ingredient, array $row, ?MeasurementUnit $unit): RecipeIngredient
    {
        $recipe = new RecipeIngredient;
        $recipe->fill([
            'ingredient_id' => $ingredient->id,
            'measurement_unit_id' => $unit?->id,
            'grams_per_unit_snapshot' => ($row['grams_per_unit_snapshot'] ?? null) ?: null,
        ]);
        $recipe->setRelation('ingredient', $ingredient);
        if ($unit !== null) {
            $recipe->setRelation('measurementUnit', $unit);
        }
        $factor = $recipe->effectiveGramsPerUnit();
        foreach (['small', 'large', 'toddler', 'maternal'] as $profile) {
            $input = $row["input_quantity_{$profile}"] ?? null;
            $recipe->setAttribute("quantity_{$profile}_grams", is_numeric($input) && (float) $input > 0
                ? round((float) $input * $factor, 4) : null);
        }

        return $recipe;
    }
}
