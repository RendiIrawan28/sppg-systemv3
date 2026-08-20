<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NonFoodItem extends Model
{
    public const CATEGORIES = [
        'APD', 'Kebersihan', 'Pencucian', 'Pemorsian', 'Distribusi',
        'Gas / Energi', 'ATK', 'Perlengkapan Dapur', 'Perlengkapan Umum', 'Lainnya',
    ];

    protected $fillable = [
        'sppg_unit_id', 'code', 'name', 'category', 'measurement_unit_id',
        'minimum_stock', 'target_stock', 'default_location', 'tracks_lot',
        'tracks_expiry', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:4',
            'target_stock' => 'decimal:4',
            'tracks_lot' => 'boolean',
            'tracks_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function sppgUnit(): BelongsTo { return $this->belongsTo(SppgUnit::class); }
    public function measurementUnit(): BelongsTo { return $this->belongsTo(MeasurementUnit::class); }
    public function inventoryLots(): HasMany { return $this->hasMany(InventoryLot::class); }

    public function scopeForUnit(Builder $query, int $unitId): Builder
    {
        return $query->where('sppg_unit_id', $unitId);
    }

    public function availableQuantity(): float
    {
        return (float) $this->inventoryLots()
            ->where('status', InventoryLot::AVAILABLE)
            ->sum('balance_quantity');
    }

    public function suggestedPurchaseQuantity(): float
    {
        return max(0, (float) $this->target_stock - $this->availableQuantity());
    }
}
