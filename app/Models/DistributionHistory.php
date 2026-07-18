<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'distribution_run_id',
        'user_id',
        'action',
        'previous_state',
        'new_state',
        'previous_status',
        'new_status',
        'notes',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
