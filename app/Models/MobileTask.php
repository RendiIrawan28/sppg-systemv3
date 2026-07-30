<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MobileTask extends Model
{
    protected $fillable = [
        'uuid', 'sppg_unit_id', 'user_id', 'task_type', 'reference_type', 'reference_id',
        'sequence_number', 'title', 'description', 'priority', 'channel', 'screen', 'payload',
        'due_at', 'status', 'reminder_sent_at', 'overdue_sent_at', 'completed_at', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'due_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'overdue_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'sequence_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $task) => $task->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
