<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingMaterialUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'ingredient_id',
        'material_name',
        'quantity',
        'measurement_unit_id',
        'unit_name',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
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
