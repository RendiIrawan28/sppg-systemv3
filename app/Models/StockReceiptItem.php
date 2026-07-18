<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceiptItem extends Model
{
    protected $fillable = [
        'stock_receipt_id',
        'procurement_request_item_id',
        'ingredient_id',
        'supplier_id',
        'ingredient_name_snapshot',
        'unit_snapshot',
        'ordered_quantity',
        'received_quantity',
        'accepted_quantity',
        'rejected_quantity',
        'ordered_quantity_kg',
        'received_quantity_kg',
        'accepted_quantity_kg',
        'rejected_quantity_kg',
        'supplier_batch_number',
        'expired_date',
        'received_temperature_celsius',
        'quality_status',
        'quality_notes',
    ];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'rejected_quantity' => 'decimal:4',
            'ordered_quantity_kg' => 'decimal:4',
            'received_quantity_kg' => 'decimal:4',
            'accepted_quantity_kg' => 'decimal:4',
            'rejected_quantity_kg' => 'decimal:4',
            'expired_date' => 'date',
            'received_temperature_celsius' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    public function procurementRequestItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequestItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
