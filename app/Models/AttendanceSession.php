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

    public const SCHEDULE_FIELDS = ['division_id', 'division_name_snapshot', 'attendance_work_schedule_id', 'shift_name_snapshot', 'scheduled_check_in_at', 'scheduled_check_out_at', 'late_tolerance_minutes_snapshot', 'late_minutes', 'punctuality_status'];

    protected $fillable = ['uuid', 'sppg_unit_id', 'user_id', 'work_date', 'check_in_at', 'check_out_at', 'check_in_device_id', 'check_out_device_id', 'check_out_source', 'source', 'status', 'notes', 'corrected_by', 'corrected_at', 'deleted_by', 'deletion_reason', ...self::SCHEDULE_FIELDS];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'corrected_at' => 'datetime', 'scheduled_check_in_at' => 'datetime', 'scheduled_check_out_at' => 'datetime', 'late_tolerance_minutes_snapshot' => 'integer', 'late_minutes' => 'integer'];
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

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkSchedule::class, 'attendance_work_schedule_id');
    }

    public function attendanceRemark(): string
    {
        return match ($this->status) {
            'permission' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Tidak Berangkat',
            'present' => match ($this->punctuality_status) {
                'late' => 'Terlambat '.($this->late_minutes ?? 0).' menit',
                'on_time' => 'Tepat Waktu', default => '-',
            },
            default => '-',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'present' => 'Hadir', 'permission' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Tidak Berangkat', default => '-'
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'rfid' => 'RFID', 'rfid_offline' => 'RFID Offline', 'manual' => 'Manual', 'system_absence' => 'Otomatis', default => '-'
        };
    }
}
