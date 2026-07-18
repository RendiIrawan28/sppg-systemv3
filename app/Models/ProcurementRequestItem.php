<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementRequestItem extends Model
{
    protected $fillable = [
        'procurement_request_id',
        'nutrition_requirement_item_id',
        'ingredient_id',
        'supplier_id',
        'ingredient_code_snapshot',
        'ingredient_name_snapshot',
        'unit_snapshot',
        'requested_quantity',
        'approved_quantity',
        'requested_quantity_kg',
        'approved_quantity_kg',
        'estimated_unit_price',
        'estimated_total_price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'approved_quantity' => 'decimal:4',
            'requested_quantity_kg' => 'decimal:4',
            'approved_quantity_kg' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total_price' => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id');
    }

    public function nutritionRequirementItem(): BelongsTo
    {
        return $this->belongsTo(NutritionRequirementItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
