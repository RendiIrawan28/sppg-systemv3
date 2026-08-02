<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MobileNotification extends Model
{
    protected $fillable = [
        'uuid', 'sppg_unit_id', 'user_id', 'mobile_task_id', 'notification_type', 'title',
        'body', 'channel', 'screen', 'payload', 'delivery_status', 'fcm_message_id',
        'error_message', 'sent_at', 'failed_at', 'read_at', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $notification) => $notification->uuid ??= (string) Str::uuid());
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MobileTask::class, 'mobile_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
