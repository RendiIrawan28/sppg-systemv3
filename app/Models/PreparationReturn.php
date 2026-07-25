<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationReturn extends Model
{
    public const WAITING = 'waiting_warehouse_verification';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'sppg_unit_id', 'preparation_session_id', 'preparation_session_item_id',
        'source_inventory_lot_id', 'destination_inventory_lot_id', 'ingredient_id',
        'return_number', 'return_date', 'ingredient_name_snapshot', 'unit_snapshot',
        'requested_quantity', 'actual_quantity', 'condition_status', 'warehouse_disposition',
        'reason', 'photo_path', 'warehouse_notes', 'status', 'returned_by', 'submitted_at',
        'verified_by', 'verified_at', 'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'requested_quantity' => 'decimal:4',
            'actual_quantity' => 'decimal:4',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PreparationSession::class, 'preparation_session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PreparationSessionItem::class, 'preparation_session_item_id');
    }

    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'source_inventory_lot_id');
    }

    public function destinationLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'destination_inventory_lot_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
