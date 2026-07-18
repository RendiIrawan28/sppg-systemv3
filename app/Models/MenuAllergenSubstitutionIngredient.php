<?php

namespace App\Models;

use App\Enums\MenuPortionProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuAllergenSubstitutionIngredient extends Model
{
    protected $fillable = [
        'menu_allergen_substitution_id',
        'ingredient_id',
        'quantity_small_grams',
        'quantity_large_grams',
        'quantity_toddler_grams',
        'quantity_maternal_grams',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_small_grams' => 'decimal:4',
            'quantity_large_grams' => 'decimal:4',
            'quantity_toddler_grams' => 'decimal:4',
            'quantity_maternal_grams' => 'decimal:4',
        ];
    }

    public function substitution(): BelongsTo
    {
        return $this->belongsTo(MenuAllergenSubstitution::class, 'menu_allergen_substitution_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function gramsFor(MenuPortionProfile $profile): float
    {
        return (float) ($this->getAttribute($profile->recipeColumn()) ?? 0);
    }
}
