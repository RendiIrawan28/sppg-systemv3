<?php

namespace App\Models;

use App\Enums\DistributionIncidentSeverity;
use App\Enums\DistributionIncidentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'distribution_run_id',
        'distribution_stop_id',
        'occurred_at',
        'category',
        'severity',
        'description',
        'immediate_action',
        'status',
        'resolved_at',
        'resolved_by',
        'photo_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'severity' => DistributionIncidentSeverity::class,
            'status' => DistributionIncidentStatus::class,
        ];
    }

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DistributionStop::class, 'distribution_stop_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
