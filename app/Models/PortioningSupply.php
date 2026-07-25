<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningSupply extends Model
{
    protected $fillable = [
        'portioning_session_id', 'source_type', 'source_id', 'source_item_id', 'ingredient_id', 'inventory_lot_id',
        'supply_name', 'quantity', 'unit_name', 'source_reference', 'condition_status', 'received_by', 'received_at',
        'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'received_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
