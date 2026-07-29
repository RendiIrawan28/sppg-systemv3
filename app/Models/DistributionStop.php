<?php

namespace App\Models;

use App\Enums\DistributionStopStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DistributionStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'distribution_run_id',
        'field_distribution_plan_destination_id',
        'route_name',
        'destination_name',
        'destination_type',
        'address',
        'contact_name',
        'contact_phone',
        'sequence_order',
        'planned_arrival_at',
        'arrived_at',
        'small_portions',
        'large_portions',
        'delivered_small_portions',
        'delivered_large_portions',
        'returned_small_portions',
        'returned_large_portions',
        'containers_sent',
        'containers_returned',
        'containers_damaged',
        'containers_lost',
        'arrival_temperature_celsius',
        'status',
        'recipient_name',
        'recipient_position',
        'signature_path',
        'handover_photo_path',
        'latitude',
        'longitude',
        'delay_minutes',
        'failure_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence_order' => 'integer',
            'planned_arrival_at' => 'datetime',
            'arrived_at' => 'datetime',
            'small_portions' => 'integer',
            'large_portions' => 'integer',
            'delivered_small_portions' => 'integer',
            'delivered_large_portions' => 'integer',
            'returned_small_portions' => 'integer',
            'returned_large_portions' => 'integer',
            'containers_sent' => 'integer',
            'containers_returned' => 'integer',
            'containers_damaged' => 'integer',
            'containers_lost' => 'integer',
            'arrival_temperature_celsius' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'delay_minutes' => 'integer',
            'status' => DistributionStopStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $stop): void {
            $stop->status ??= DistributionStopStatus::Planned;
        });

        static::saving(function (self $stop): void {
            if ($stop->planned_arrival_at && $stop->arrived_at) {
                $stop->delay_minutes = max(
                    0,
                    $stop->planned_arrival_at->diffInMinutes($stop->arrived_at, false),
                );
            } else {
                $stop->delay_minutes = 0;
            }
        });
    }

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }
    public function fieldDistributionPlanDestination(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\FieldDistributionPlanDestination::class
        );
    }
    public function containerCollectionTask(): HasOne
    {
        return $this->hasOne(ContainerCollectionTask::class);
    }

}
