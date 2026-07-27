<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningRouteAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'field_distribution_plan_destination_id',
        'route_name',
        'destination_name',
        'destination_type',
        'address',
        'contact_name',
        'contact_phone',
        'planned_arrival_at',
        'planned_departure_at',
        'latitude',
        'longitude',
        'target_small_portions',
        'target_large_portions',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_arrival_at' => 'datetime',
            'planned_departure_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'target_small_portions' => 'integer',
            'target_large_portions' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }

    public function fieldDistributionPlanDestination(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlanDestination::class);
    }
}
