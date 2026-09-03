<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AttendanceWorkSchedule extends Model
{
    protected $fillable = ['sppg_unit_id', 'division_id', 'name', 'start_time', 'end_time', 'late_tolerance_minutes', 'work_days', 'is_default', 'is_active', 'effective_from', 'effective_until', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['work_days' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean', 'late_tolerance_minutes' => 'integer', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $schedule) => $schedule->uuid ??= (string) Str::uuid());
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AttendanceWorkScheduleAssignment::class);
    }

    public function appliesToDate(CarbonInterface $date): bool
    {
        return $this->is_active
            && (! $this->effective_from || $date->toDateString() >= $this->effective_from->toDateString())
            && (! $this->effective_until || $date->toDateString() <= $this->effective_until->toDateString())
            && in_array($date->isoWeekday(), array_map('intval', $this->work_days ?? []), true);
    }

    public function spansMidnight(): bool
    {
        return substr($this->end_time, 0, 5) <= substr($this->start_time, 0, 5);
    }
}
