<?php

namespace App\Models;

use App\Enums\MenuPortionProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id',
        'ingredient_id',
        'ingredient_portion_standard_id',
        'portion_source',
        'portion_override',
        'measurement_unit_id',
        'input_unit_code_snapshot',
        'input_unit_name_snapshot',
        'grams_per_unit_snapshot',
        'input_quantity_small',
        'input_quantity_large',
        'input_quantity_toddler',
        'input_quantity_maternal',
        'quantity',
        'quantity_grams',
        'quantity_small_grams',
        'quantity_large_grams',
        'quantity_toddler_grams',
        'quantity_maternal_grams',
        'cooking_loss_percent',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'grams_per_unit_snapshot' => 'decimal:4',
            'input_quantity_small' => 'decimal:4',
            'input_quantity_large' => 'decimal:4',
            'input_quantity_toddler' => 'decimal:4',
            'input_quantity_maternal' => 'decimal:4',
            'quantity' => 'decimal:4',
            'quantity_grams' => 'decimal:4',
            'quantity_small_grams' => 'decimal:4',
            'quantity_large_grams' => 'decimal:4',
            'quantity_toddler_grams' => 'decimal:4',
            'quantity_maternal_grams' => 'decimal:4',
            'cooking_loss_percent' => 'decimal:2',
            'portion_override' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            $unit = $record->measurementUnit;

            if ($unit) {
                $record->input_unit_code_snapshot = $record->input_unit_code_snapshot ?: $unit->code;
                $record->input_unit_name_snapshot = $record->input_unit_name_snapshot ?: trim($unit->name . ($unit->symbol ? " ({$unit->symbol})" : ''));
            }

            $factor = $record->effectiveGramsPerUnit();

            foreach ([
                'small' => 'quantity_small_grams',
                'large' => 'quantity_large_grams',
                'toddler' => 'quantity_toddler_grams',
                'maternal' => 'quantity_maternal_grams',
            ] as $profile => $gramsColumn) {
                $inputColumn = "input_quantity_{$profile}";
                $input = $record->getAttribute($inputColumn);

                if ($input !== null && (float) $input > 0 && $factor > 0) {
                    $record->setAttribute($gramsColumn, round((float) $input * $factor, 4));
                }
            }

            $small = (float) ($record->quantity_small_grams ?? $record->quantity_grams ?? 0);
            $record->quantity_grams = $small;
            $record->quantity = $record->input_quantity_small ?: ($record->quantity ?: $small);
        });
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function portionStandard(): BelongsTo
    {
        return $this->belongsTo(IngredientPortionStandard::class, 'ingredient_portion_standard_id');
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function gramsFor(MenuPortionProfile $profile): float
    {
        $specific = $this->getAttribute($profile->recipeColumn());

        if ($specific !== null && (float) $specific > 0) {
            return (float) $specific;
        }

        $fallback = match ($profile) {
            MenuPortionProfile::Small, MenuPortionProfile::Toddler => $this->quantity_small_grams,
            MenuPortionProfile::Large, MenuPortionProfile::Maternal => $this->quantity_large_grams,
        };

        return (float) ($fallback ?? $this->quantity_grams ?? 0);
    }


    public function inputQuantityFor(MenuPortionProfile $profile): float
    {
        $column = match ($profile) {
            MenuPortionProfile::Small => 'input_quantity_small',
            MenuPortionProfile::Large => 'input_quantity_large',
            MenuPortionProfile::Toddler => 'input_quantity_toddler',
            MenuPortionProfile::Maternal => 'input_quantity_maternal',
        };

        $specific = $this->getAttribute($column);

        if ($specific !== null && (float) $specific > 0) {
            return (float) $specific;
        }

        $fallbackColumn = match ($profile) {
            MenuPortionProfile::Small, MenuPortionProfile::Toddler => 'input_quantity_small',
            MenuPortionProfile::Large, MenuPortionProfile::Maternal => 'input_quantity_large',
        };
        $fallback = $this->getAttribute($fallbackColumn);

        if ($fallback !== null && (float) $fallback > 0) {
            return (float) $fallback;
        }

        $factor = $this->effectiveGramsPerUnit();
        $grams = $this->gramsFor($profile);

        return $factor > 0 ? round($grams / $factor, 4) : $grams;
    }

    public function unitSnapshot(): string
    {
        if (filled($this->input_unit_code_snapshot)) {
            return (string) $this->input_unit_code_snapshot;
        }

        $unit = $this->measurementUnit;

        if ($unit) {
            return (string) ($unit->symbol ?: $unit->code ?: $unit->name);
        }

        return 'unit';
    }

    public function effectiveGramsPerUnit(): float
    {
        $snapshot = (float) ($this->grams_per_unit_snapshot ?? 0);

        if ($snapshot > 0) {
            return $snapshot;
        }

        $unit = $this->measurementUnit;

        if ($unit && (float) $unit->to_base_factor > 0) {
            return (float) $unit->to_base_factor;
        }

        $ingredient = $this->ingredient;

        if ($ingredient && (float) ($ingredient->grams_per_unit ?? 0) > 0) {
            return (float) $ingredient->grams_per_unit;
        }

        return 1.0;
    }
}
