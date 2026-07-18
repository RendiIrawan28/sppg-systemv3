<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'unit_type',
        'to_base_factor',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'to_base_factor' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(
            RecipeIngredient::class
        );
    }
}