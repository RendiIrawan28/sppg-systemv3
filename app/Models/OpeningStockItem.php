<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockItem extends Model
{
    protected $fillable = ['opening_stock_id', 'ingredient_id', 'non_food_item_id', 'inventory_lot_id', 'ingredient_name_snapshot', 'unit_snapshot', 'quantity', 'lot_number', 'expired_date', 'storage_type', 'location_name', 'condition_notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'expired_date' => 'date'];
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function nonFoodItem(): BelongsTo { return $this->belongsTo(NonFoodItem::class); }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }
}
