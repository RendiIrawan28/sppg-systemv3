<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWorkScheduleAssignment extends Model
{
    protected $fillable = ['sppg_unit_id', 'attendance_work_schedule_id', 'user_id', 'effective_from', 'effective_until', 'is_active', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean'];
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkSchedule::class, 'attendance_work_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
