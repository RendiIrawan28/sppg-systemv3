<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientNutrition extends Model
{
    use HasFactory;

    /**
     * Ditulis eksplisit agar Eloquent selalu menggunakan
     * nama tabel plural yang benar.
     */
    protected $table = 'ingredient_nutritions';

    protected $fillable = [
        'ingredient_id',
        'nutrition_component_id',
        'value_per_100g',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'value_per_100g' => 'decimal:4',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(
            Ingredient::class,
            'ingredient_id'
        );
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(
            NutritionComponent::class,
            'nutrition_component_id'
        );
    }
}