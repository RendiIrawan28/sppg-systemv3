<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    use HasUuids;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_HANDOVER = 'handover_to_preparation';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_OPENING_BALANCE = 'opening_balance';

    public const TYPE_RETURN_FROM_PREPARATION = 'return_from_preparation';

    public const TYPE_RETURN_FROM_PROCESSING = 'return_from_processing';

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'ingredient_id', 'inventory_lot_id', 'ingredient_name_snapshot', 'unit_snapshot',
        'movement_type', 'movement_date', 'quantity_in_kg', 'quantity_out_kg', 'quantity_in', 'quantity_out', 'source_type', 'source_id',
        'reference_number', 'supplier_batch_number', 'expired_date', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'quantity_in_kg' => 'decimal:4',
            'quantity_out_kg' => 'decimal:4',
            'quantity_in' => 'decimal:4',
            'quantity_out' => 'decimal:4',
            'expired_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->uuid ??= (string) Str::uuid();
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }
}
