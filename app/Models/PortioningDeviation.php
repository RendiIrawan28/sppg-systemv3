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
        'corrective_action',
        'status',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
