<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NutritionComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ingredientNutritions(): HasMany
    {
        return $this->hasMany(
            IngredientNutrition::class,
            'nutrition_component_id'
        );
    }

    public function standards(): HasMany
    {
        return $this->hasMany(
            NutritionStandard::class,
            'nutrition_component_id'
        );
    }

    public function menuNutritionSummaries(): HasMany
    {
        return $this->hasMany(
            MenuNutritionSummary::class,
            'nutrition_component_id'
        );
    }
}