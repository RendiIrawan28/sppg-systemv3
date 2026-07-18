<?php

namespace App\Models;

use App\Enums\MenuAudience;
use App\Enums\MenuPortionProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_id',
        'name',
        'item_type',
        'menu_audience',
        'portion_size',
        'portion_weight_grams',
        'portion_weight_small_grams',
        'portion_weight_large_grams',
        'portion_weight_toddler_grams',
        'portion_weight_maternal_grams',
        'sort_order',
        'preparation_notes',
    ];

    protected function casts(): array
    {
        return [
            'menu_audience' => MenuAudience::class,
            'portion_weight_grams' => 'decimal:3',
            'portion_weight_small_grams' => 'decimal:3',
            'portion_weight_large_grams' => 'decimal:3',
            'portion_weight_toddler_grams' => 'decimal:3',
            'portion_weight_maternal_grams' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('id');
    }

    public function allergenSubstitutions(): HasMany
    {
        return $this->hasMany(MenuAllergenSubstitution::class, 'original_menu_item_id');
    }

    public function portionWeightFor(MenuPortionProfile $profile): float
    {
        $value = $this->getAttribute($profile->portionWeightColumn());

        return (float) ($value ?? $this->portion_weight_grams ?? 0);
    }
}
