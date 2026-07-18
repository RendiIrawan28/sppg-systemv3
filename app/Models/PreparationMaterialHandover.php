<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PreparationMaterialHandover extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_HANDED_OVER = 'handed_over';

    public const STATUS_RECEIVED = 'received_by_preparation';

    public const STATUS_INSPECTED = 'inspected';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_WASTE_RECORDED = 'waste_recorded';

    public const STATUS_HANDED_OVER_TO_PROCESSING = 'handed_over_to_processing';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'field_distribution_plan_id',
        'processing_batch_id',
        'handover_number',
        'handover_date',
        'status',
        'warehouse_officer_name',
        'preparation_officer_name',
        'notes',
        'created_by',
        'handed_over_by',
        'received_by',
        'inspected_by',
        'prepared_by',
        'waste_recorded_by',
        'handed_over_to_processing_by',
        'completed_by',
        'handed_over_at',
        'received_at',
        'inspected_at',
        'prepared_at',
        'waste_recorded_at',
        'handed_over_to_processing_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
            'handed_over_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'prepared_at' => 'datetime',
            'waste_recorded_at' => 'datetime',
            'handed_over_to_processing_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $handover): void {
            $handover->uuid ??= (string) Str::uuid();
            $handover->status ??= self::STATUS_DRAFT;

            if (blank($handover->handover_number)) {
                $year = ($handover->handover_date ?? now())->format('Y');
                $sequence = self::query()
                    ->where('sppg_unit_id', $handover->sppg_unit_id)
                    ->whereYear('handover_date', $year)
                    ->withTrashed()
                    ->count() + 1;

                $handover->handover_number = sprintf('STP/%s/%04d', $year, $sequence);
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

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class);
    }

    public function processingBatch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PreparationMaterialHandoverItem::class);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPreparationEditable(): bool
    {
        return in_array($this->status, [
            self::STATUS_HANDED_OVER,
            self::STATUS_RECEIVED,
            self::STATUS_INSPECTED,
            self::STATUS_PREPARED,
            self::STATUS_WASTE_RECORDED,
        ], true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_HANDED_OVER_TO_PROCESSING,
            self::STATUS_COMPLETED,
        ], true);
    }
}
