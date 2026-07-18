<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionRequirementItem extends Model
{
    protected $fillable = [
        'nutrition_requirement_plan_id',
        'ingredient_id',
        'ingredient_code_snapshot',
        'ingredient_name_snapshot',
        'unit_snapshot',
        'quantity_per_portion',
        'quantity_per_portion_grams',
        'effective_portions',
        'base_quantity',
        'base_quantity_grams',
        'buffer_percent',
        'total_quantity',
        'total_quantity_grams',
        'total_quantity_kg',
        'edible_portion_percent',
        'loss_factor',
        'rounding_increment',
        'unrounded_quantity',
        'estimated_unit_price',
        'estimated_total_price',
        'recipe_components',
        'calculation_breakdown',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_portion' => 'decimal:4',
            'quantity_per_portion_grams' => 'decimal:4',
            'effective_portions' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'base_quantity_grams' => 'decimal:4',
            'buffer_percent' => 'decimal:2',
            'total_quantity' => 'decimal:4',
            'total_quantity_grams' => 'decimal:4',
            'total_quantity_kg' => 'decimal:4',
            'edible_portion_percent' => 'decimal:2',
            'loss_factor' => 'decimal:4',
            'rounding_increment' => 'decimal:4',
            'unrounded_quantity' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total_price' => 'decimal:2',
            'calculation_breakdown' => 'array',
        ];
    }


    public function getCalculationBreakdownSummaryAttribute(): string
    {
        $rows = collect($this->calculation_breakdown ?? []);

        if ($rows->isEmpty()) {
            return '-';
        }

        return $rows->map(function (array $row): string {
            $name = $row['name'] ?? $row['code'] ?? 'Kelompok';
            $effective = number_format((float) ($row['effective_portions'] ?? 0), 2, ',', '.');
            $unit = trim((string) ($row['unit_snapshot'] ?? $this->unit_snapshot ?? 'unit'));
            $quantity = number_format((float) ($row['quantity'] ?? 0), 2, ',', '.');
            $grams = number_format((float) ($row['quantity_grams'] ?? 0), 2, ',', '.');
            $components = trim((string) ($row['components'] ?? ''));
            $suffix = $components !== '' ? " ({$components})" : '';

            return "{$name}: {$effective} porsi = {$quantity} {$unit} / {$grams} gram{$suffix}";
        })->implode("\n");
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            NutritionRequirementPlan::class,
            'nutrition_requirement_plan_id'
        );
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
