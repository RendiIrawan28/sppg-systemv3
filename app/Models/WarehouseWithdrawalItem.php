<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseWithdrawalItem extends Model
{
    protected $fillable = ['warehouse_withdrawal_id', 'ingredient_id', 'non_food_item_id', 'inventory_lot_id', 'ingredient_name_snapshot', 'lot_number_snapshot', 'expiry_date_snapshot', 'unit_snapshot', 'requested_quantity', 'actual_quantity', 'pickup_temperature_celsius', 'photo_path', 'taken_quantity_kg', 'verified_quantity_kg', 'condition_status', 'notes'];

    protected function casts(): array
    {
        return ['expiry_date_snapshot' => 'date', 'requested_quantity' => 'decimal:4', 'actual_quantity' => 'decimal:4', 'pickup_temperature_celsius' => 'decimal:2', 'taken_quantity_kg' => 'decimal:4', 'verified_quantity_kg' => 'decimal:4'];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(WarehouseWithdrawal::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function nonFoodItem(): BelongsTo { return $this->belongsTo(NonFoodItem::class); }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}
