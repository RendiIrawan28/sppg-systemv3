<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerCollectionTask extends Model
{
    public const PENDING = 'pending';
    public const PARTIAL = 'partial';
    public const COLLECTED = 'collected';

    protected $fillable = [
        'sppg_unit_id', 'distribution_run_id', 'distribution_stop_id', 'delivery_date',
        'destination_name', 'destination_type', 'address', 'contact_name', 'contact_phone',
        'target_containers', 'collected_containers', 'remaining_containers', 'status',
        'available_at', 'completed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'target_containers' => 'integer',
            'collected_containers' => 'integer',
            'remaining_containers' => 'integer',
            'available_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }

    public function distributionStop(): BelongsTo
    {
        return $this->belongsTo(DistributionStop::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContainerCollectionItem::class)->latest('collected_at');
    }
}
