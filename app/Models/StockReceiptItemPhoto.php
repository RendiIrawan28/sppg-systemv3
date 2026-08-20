<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceiptItemPhoto extends Model
{
    protected $fillable = [
        'stock_receipt_id', 'stock_receipt_item_id', 'item_name_snapshot', 'photo_path', 'original_name', 'uploaded_by',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockReceiptItem::class, 'stock_receipt_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
