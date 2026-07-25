<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    public const DRAFT = 'draft';

    public const VERIFIED = 'verified';

    protected $fillable = ['sppg_unit_id', 'inventory_lot_id', 'unit_snapshot', 'adjustment_number', 'adjustment_date', 'type', 'system_quantity', 'actual_quantity', 'difference_quantity', 'system_quantity_kg', 'actual_quantity_kg', 'difference_quantity_kg', 'status', 'reason', 'created_by', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'system_quantity' => 'decimal:4', 'actual_quantity' => 'decimal:4', 'difference_quantity' => 'decimal:4', 'system_quantity_kg' => 'decimal:4', 'actual_quantity_kg' => 'decimal:4', 'difference_quantity_kg' => 'decimal:4', 'verified_at' => 'datetime'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
