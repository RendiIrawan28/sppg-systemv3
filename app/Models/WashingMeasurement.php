<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'phase', 'measured_at', 'water_temperature_celsius',
        'minimum_temperature_celsius', 'maximum_temperature_celsius', 'water_ph',
        'sanitizer_concentration_ppm', 'is_within_limit', 'corrective_action',
        'measured_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'water_temperature_celsius' => 'decimal:2',
            'minimum_temperature_celsius' => 'decimal:2',
            'maximum_temperature_celsius' => 'decimal:2',
            'water_ph' => 'decimal:2',
            'sanitizer_concentration_ppm' => 'decimal:2',
            'is_within_limit' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $measurement): void {
            $actual = (float) $measurement->water_temperature_celsius;
            $minimum = $measurement->minimum_temperature_celsius;
            $maximum = $measurement->maximum_temperature_celsius;
            $withinMinimum = $minimum === null || $actual >= (float) $minimum;
            $withinMaximum = $maximum === null || $actual <= (float) $maximum;
            $measurement->is_within_limit = $withinMinimum && $withinMaximum;
            $measurement->measured_by ??= auth()->id();
        });
    }

    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
    public function measurer(): BelongsTo { return $this->belongsTo(User::class, 'measured_by'); }
}
