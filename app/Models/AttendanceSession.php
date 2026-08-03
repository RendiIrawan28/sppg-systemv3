<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AttendanceSession extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'sppg_unit_id', 'user_id', 'work_date', 'check_in_at', 'check_out_at', 'check_in_device_id', 'check_out_device_id', 'source', 'status', 'notes', 'corrected_by', 'corrected_at', 'deleted_by', 'deletion_reason'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'corrected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkInDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'check_in_device_id');
    }

    public function checkOutDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'check_out_device_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AttendanceSessionHistory::class);
    }

    public function durationMinutes(): ?int
    {
        return $this->check_in_at && $this->check_out_at
            ? (int) $this->check_in_at->diffInMinutes($this->check_out_at)
            : null;
    }
}
