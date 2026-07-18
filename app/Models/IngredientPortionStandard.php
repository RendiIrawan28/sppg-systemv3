<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientPortionStandard extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id', 'ingredient_id', 'measurement_unit_id', 'component_type',
        'small_quantity', 'large_quantity', 'toddler_quantity', 'maternal_quantity',
        'grams_per_unit', 'source', 'source_row', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'small_quantity' => 'decimal:4', 'large_quantity' => 'decimal:4',
            'toddler_quantity' => 'decimal:4', 'maternal_quantity' => 'decimal:4',
            'grams_per_unit' => 'decimal:4', 'source_row' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }
}
