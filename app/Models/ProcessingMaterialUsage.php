<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingMaterialUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'processing_material_stock_id',
        'source_type',
        'source_id',
        'source_item_id',
        'ingredient_id',
        'inventory_lot_id',
        'material_name',
        'quantity',
        'measurement_unit_id',
        'unit_name',
        'source_reference',
        'condition_status',
        'received_by',
        'received_at',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(ProcessingMaterialStock::class, 'processing_material_stock_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProcessingReturn::class, 'processing_material_usage_id');
    }
}
