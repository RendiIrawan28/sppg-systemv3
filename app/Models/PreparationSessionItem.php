<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreparationSessionItem extends Model
{
    protected $fillable = ['preparation_session_id', 'warehouse_withdrawal_item_id', 'ingredient_id', 'inventory_lot_id', 'ingredient_name_snapshot', 'unit_snapshot', 'received_quantity', 'processed_quantity', 'waste_quantity', 'condition_status', 'received_weight_kg', 'clean_weight_kg', 'waste_weight_kg', 'process_method', 'thawing_temperature_celsius', 'notes'];

    protected function casts(): array
    {
        return ['received_quantity' => 'decimal:4', 'processed_quantity' => 'decimal:4', 'waste_quantity' => 'decimal:4', 'received_weight_kg' => 'decimal:4', 'clean_weight_kg' => 'decimal:4', 'waste_weight_kg' => 'decimal:4', 'thawing_temperature_celsius' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PreparationSession::class, 'preparation_session_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PreparationReturn::class);
    }
}
