<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientAllergen extends Model
{
    use HasFactory;

    protected $table = 'ingredient_allergen';

    protected $fillable = [
        'ingredient_id',
        'allergen_id',
        'contamination_risk',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ingredient_id' => 'integer',
            'allergen_id' => 'integer',
            'contamination_risk' => 'boolean',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }
}
