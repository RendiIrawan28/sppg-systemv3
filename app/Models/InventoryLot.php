<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    public const AVAILABLE = 'available';

    public const QUARANTINE = 'quarantine';

    public const REJECTED = 'rejected';

    public const DEPLETED = 'depleted';

    protected $fillable = ['sppg_unit_id', 'warehouse_id', 'ingredient_id', 'non_food_item_id', 'stock_receipt_item_id', 'unit_snapshot', 'initial_quantity', 'balance_quantity', 'lot_number', 'expired_date', 'location_name', 'storage_type', 'status', 'initial_quantity_kg', 'balance_quantity_kg'];

    protected function casts(): array
    {
        return [
            'expired_date' => 'date',
            'initial_quantity' => 'decimal:4',
            'balance_quantity' => 'decimal:4',
            'initial_quantity_kg' => 'decimal:4',
            'balance_quantity_kg' => 'decimal:4',
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

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function nonFoodItem(): BelongsTo { return $this->belongsTo(NonFoodItem::class); }

    public function stockItem(): Ingredient|NonFoodItem|null
    {
        return $this->ingredient ?: $this->nonFoodItem;
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(StockReceiptItem::class, 'stock_receipt_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function withdrawalItems(): HasMany
    {
        return $this->hasMany(WarehouseWithdrawalItem::class);
    }
}
