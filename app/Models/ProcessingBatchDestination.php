<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingBatchDestination extends Model
{
    protected $fillable = [
        'processing_batch_id',
        'field_distribution_plan_destination_id',
        'destination_type',
        'destination_id',
        'destination_name_snapshot',
        'route_name',
        'sequence_order',
        'small_portions',
        'large_portions',
        'total_portions',
        'planned_departure_at',
        'planned_arrival_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'destination_id' => 'integer',
            'sequence_order' => 'integer',
            'small_portions' => 'integer',
            'large_portions' => 'integer',
            'total_portions' => 'integer',
            'planned_departure_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $destination->total_portions =
                (int) $destination->small_portions +
                (int) $destination->large_portions;
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function fieldDistributionPlanDestination(): BelongsTo
    {
        return $this->belongsTo(
            FieldDistributionPlanDestination::class,
            'field_distribution_plan_destination_id',
        );
    }
}
