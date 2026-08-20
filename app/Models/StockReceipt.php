<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StockReceipt extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECEIVED = 'received';

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'warehouse_id', 'procurement_request_id', 'supplier_id', 'receipt_number', 'receipt_date', 'status',
        'received_by_name', 'notes', 'documentation_path', 'created_by', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $receipt): void {
            $receipt->uuid ??= (string) Str::uuid();
            $receipt->status ??= self::STATUS_DRAFT;

            if (blank($receipt->receipt_number)) {
                $year = ($receipt->receipt_date ?? now())->format('Y');
                $sequence = self::query()
                    ->where('sppg_unit_id', $receipt->sppg_unit_id)
                    ->whereYear('receipt_date', $year)
                    ->withTrashed()
                    ->count() + 1;

                $receipt->receipt_number = sprintf('PBM/%s/%04d', $year, $sequence);
            }
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

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class);
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function itemPhotos(): HasMany
    {
        return $this->hasMany(StockReceiptItemPhoto::class);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
