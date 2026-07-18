<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryPeriodHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_period_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
