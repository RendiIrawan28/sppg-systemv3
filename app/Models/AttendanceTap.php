<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttendanceTap extends Model
{
    protected $fillable = ['uuid', 'sppg_unit_id', 'attendance_device_id', 'user_id', 'attendance_session_id', 'request_id', 'uid_snapshot', 'action', 'result', 'response_message', 'tapped_at', 'received_at', 'is_offline', 'response_payload'];

    protected function casts(): array
    {
        return ['tapped_at' => 'datetime', 'received_at' => 'datetime', 'is_offline' => 'boolean', 'response_payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
