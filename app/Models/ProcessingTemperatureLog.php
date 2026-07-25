<?php

namespace App\Models;

use App\Enums\ProcessingTemperatureCheckpoint;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcessingTemperatureLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'checked_at',
        'checkpoint',
        'product_name',
        'temperature_celsius',
        'minimum_temperature',
        'maximum_temperature',
        'is_within_limit',
        'corrective_action',
        'measured_by',
        'measured_name_snapshot',
        'photo_path',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'checkpoint' => ProcessingTemperatureCheckpoint::class,
            'temperature_celsius' => 'decimal:2',
            'minimum_temperature' => 'decimal:2',
            'maximum_temperature' => 'decimal:2',
            'is_within_limit' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            if ($log->measured_by && blank($log->measured_name_snapshot)) {
                $log->measured_name_snapshot = User::query()->whereKey($log->measured_by)->value('name');
            }
            $temperature = (float) $log->temperature_celsius;
            $minimum = $log->minimum_temperature !== null
                ? (float) $log->minimum_temperature
                : null;
            $maximum = $log->maximum_temperature !== null
                ? (float) $log->maximum_temperature
                : null;

            $withinMinimum = $minimum === null || $temperature >= $minimum;
            $withinMaximum = $maximum === null || $temperature <= $maximum;

            $log->is_within_limit = $withinMinimum && $withinMaximum;
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }
}
