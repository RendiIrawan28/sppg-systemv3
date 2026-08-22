<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProcurementRequest extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted_to_finance';

    public const STATUS_REVISION = 'finance_revision';

    public const STATUS_FINANCE_VERIFIED = 'verified_by_finance';

    public const STATUS_APPROVED = 'approved_by_head';

    public const STATUS_ORDERED = 'ordered_by_warehouse';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'request_number', 'request_date', 'needed_date',
        'nutrition_requirement_plan_id', 'field_distribution_plan_id', 'warehouse_id', 'procurement_type', 'status',
        'price_status', 'price_finalized_by', 'price_finalized_at',
        'total_items', 'estimated_total_amount', 'notes', 'finance_notes',
        'created_by', 'submitted_by', 'approved_by', 'ordered_by',
        'submitted_at', 'approved_at', 'ordered_at',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'needed_date' => 'date',
            'total_items' => 'integer',
            'estimated_total_amount' => 'decimal:2',
            'price_finalized_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'ordered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->uuid ??= (string) Str::uuid();
            $request->status ??= self::STATUS_DRAFT;
            $request->price_status ??= 'draft';

            if (blank($request->request_number)) {
                $year = ($request->request_date ?? now())->format('Y');
                $sequence = self::query()
                    ->where('sppg_unit_id', $request->sppg_unit_id)
                    ->whereYear('request_date', $year)
                    ->withTrashed()
                    ->count() + 1;
                $request->request_number = sprintf('PB/%s/%04d', $year, $sequence);
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

    public function nutritionRequirementPlan(): BelongsTo
    {
        return $this->belongsTo(NutritionRequirementPlan::class);
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementRequestItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function priceFinalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_finalized_by');
    }

    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVISION], true);
    }

    /**
     * Daftar bahan masih boleh dikoreksi sampai pemeriksaan keuangan dilakukan.
     * Status submitted tetap dibuka agar Akuntan dapat menambah, menghapus,
     * atau mengoreksi item sebelum menekan Verifikasi Keuangan.
     */
    public function itemsAreEditable(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_REVISION,
            self::STATUS_SUBMITTED,
        ], true) && $this->price_status !== 'finalized';
    }

    public function priceIsEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVISION, self::STATUS_SUBMITTED, self::STATUS_FINANCE_VERIFIED], true)
            && $this->price_status !== 'finalized';
    }
}
