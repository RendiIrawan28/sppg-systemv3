<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Allergen extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
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

    public function ingredientLinks(): HasMany
    {
        return $this->hasMany(IngredientAllergen::class);
    }

    public function beneficiaryLinks(): HasMany
    {
        return $this->hasMany(BeneficiaryAllergen::class);
    }

    public function menuSummaries(): HasMany
    {
        return $this->hasMany(MenuAllergenSummary::class);
    }
}
