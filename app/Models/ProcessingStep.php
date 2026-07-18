<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcessingStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'step_name',
        'started_at',
        'completed_at',
        'duration_minutes',
        'temperature_celsius',
        'notes',
        'photo_path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'temperature_celsius' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            if ($step->started_at && $step->completed_at) {
                $step->duration_minutes = max(
                    0,
                    $step->started_at->diffInMinutes($step->completed_at),
                );
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }
}
