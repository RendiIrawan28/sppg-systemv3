<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningTemperatureLog extends Model
{
    protected $fillable = [
        'portioning_session_id', 'checked_at', 'checkpoint', 'temperature_celsius', 'minimum_temperature',
        'maximum_temperature', 'is_within_limit', 'corrective_action', 'photo_path', 'measured_by',
        'measured_name_snapshot', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime', 'temperature_celsius' => 'decimal:2', 'minimum_temperature' => 'decimal:2',
            'maximum_temperature' => 'decimal:2', 'is_within_limit' => 'boolean', 'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            $temperature = (float) $log->temperature_celsius;
            $minimum = $log->minimum_temperature !== null ? (float) $log->minimum_temperature : null;
            $maximum = $log->maximum_temperature !== null ? (float) $log->maximum_temperature : null;
            $log->is_within_limit = ($minimum === null || $temperature >= $minimum)
                && ($maximum === null || $temperature <= $maximum);
            if ($log->measured_by && blank($log->measured_name_snapshot)) {
                $log->measured_name_snapshot = User::query()->whereKey($log->measured_by)->value('name');
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
