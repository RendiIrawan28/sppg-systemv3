<?php

namespace App\Models;

use App\Enums\PortioningDeviationSeverity;
use App\Enums\PortioningDeviationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningDeviation extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'detected_at',
        'category',
        'severity',
        'description',
        'immediate_action',
        'corrective_action',
        'photo_path',
        'status',
        'reported_by',
        'resolved_at',
        'resolved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'severity' => PortioningDeviationSeverity::class,
            'status' => PortioningDeviationStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $deviation): void {
            $deviation->reported_by ??= auth()->id();
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
