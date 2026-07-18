<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDistributionPlanHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_distribution_plan_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_name_snapshot',
        'notes',
        'snapshot',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class, 'field_distribution_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
