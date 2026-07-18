<?php

namespace App\Models;

use App\Enums\WashingDeviationSeverity;
use App\Enums\WashingDeviationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingDeviation extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'occurred_at', 'category', 'severity', 'description',
        'immediate_action', 'status', 'resolved_at', 'resolved_by', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'severity' => WashingDeviationSeverity::class,
            'status' => WashingDeviationStatus::class,
        ];
    }

    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
