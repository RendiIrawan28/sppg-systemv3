<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'measurement_unit_id',
        'code',
        'name',
        'category',
        'edible_portion_percent',
        'grams_per_unit',
        'reference_price',
        'loss_factor',
        'rounding_increment',
        'rounding_mode',
        'nutrition_reference_grams',
        'nutrition_source',
        'specification',
        'source_row',
        'description',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'edible_portion_percent' => 'decimal:2',
            'grams_per_unit' => 'decimal:4',
            'reference_price' => 'decimal:2',
            'loss_factor' => 'decimal:4',
            'rounding_increment' => 'decimal:4',
            'nutrition_reference_grams' => 'decimal:4',
            'source_row' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function edibleFactor(): float
    {
        $percent = (float) ($this->edible_portion_percent ?? 100);

        return max(0.01, min(1, $percent / 100));
    }

    public function effectiveLossFactor(): float
    {
        return max(0.0001, (float) ($this->loss_factor ?: 1));
    }

    public function roundPurchaseQuantity(float $quantity): float
    {
        $increment = (float) ($this->rounding_increment ?? 0);

        if ($increment <= 0) {
            return round($quantity, 4);
        }

        $scaled = $quantity / $increment;
        $rounded = match ($this->rounding_mode) {
            'nearest' => round($scaled),
            'down' => floor($scaled),
            default => ceil($scaled - 1.0E-9),
        };

        return round($rounded * $increment, 4);
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class
        );
    }

    public function nutritions(): HasMany
    {
        return $this->hasMany(
            IngredientNutrition::class,
            'ingredient_id'
        );
    }

    

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(
            RecipeIngredient::class
        );
    }

    public function portionStandards(): HasMany
    {
        return $this->hasMany(IngredientPortionStandard::class);
    }

    public function allergenLinks(): HasMany
    {
        return $this->hasMany(IngredientAllergen::class);
    }
}
