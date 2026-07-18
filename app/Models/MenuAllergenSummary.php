<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuAllergenSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_id',
        'allergen_id',
        'source_ingredient_count',
        'has_cross_contamination_risk',
        'source_ingredients',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'allergen_id' => 'integer',
            'source_ingredient_count' => 'integer',
            'has_cross_contamination_risk' => 'boolean',
            'source_ingredients' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }
}
