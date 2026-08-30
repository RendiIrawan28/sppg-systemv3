<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingMaterialStock extends Model
{
    public const AVAILABLE = 'available';
    public const DEPLETED = 'depleted';

    protected $fillable = [
        'sppg_unit_id',
        'source_type',
        'source_id',
        'source_item_id',
        'ingredient_id',
        'inventory_lot_id',
        'material_name',
        'measurement_unit_id',
        'unit_name',
        'received_quantity',
        'available_quantity',
        'source_reference',
        'received_by',
        'received_at',
        'expires_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:4',
            'available_quantity' => 'decimal:4',
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ProcessingMaterialUsage::class, 'processing_material_stock_id');
    }

    public function refreshStatus(): void
    {
        $this->forceFill([
            'status' => (float) $this->available_quantity > 0.0001
                ? self::AVAILABLE
                : self::DEPLETED,
        ])->saveQuietly();
    }
}
