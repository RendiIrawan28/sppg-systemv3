<?php

namespace App\Models;

use App\Enums\SecurityShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SecurityShift extends Model
{
    use HasFactory;

    public const DURATION_HOURS = 12;

    public const REPORT_INTERVAL_HOURS = 3;

    public const EXPECTED_REPORTS = 4;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'officer_id',
        'officer_name_snapshot',
        'started_at',
        'scheduled_end_at',
        'completed_at',
        'status',
        'reports_expected',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => SecurityShiftStatus::class,
            'reports_expected' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $shift): void {
            $shift->uuid ??= (string) Str::uuid();
            $shift->started_at ??= now();
            $shift->scheduled_end_at ??= $shift->started_at->copy()->addHours(self::DURATION_HOURS);
            $shift->status ??= SecurityShiftStatus::Active;
            $shift->reports_expected ??= self::EXPECTED_REPORTS;
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(SecurityReport::class)->orderBy('sequence_number');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SecurityShiftStatus::Active->value);
    }

    public function getNextReportSequenceAttribute(): ?int
    {
        $next = ((int) $this->reports()->max('sequence_number')) + 1;

        return $next <= $this->reports_expected ? $next : null;
    }

    public function getNextReportDueAtAttribute(): ?Carbon
    {
        if (! $this->next_report_sequence) {
            return null;
        }

        return $this->started_at
            ->copy()
            ->addHours(self::REPORT_INTERVAL_HOURS * $this->next_report_sequence);
    }

    public function isReportDue(?Carbon $at = null): bool
    {
        return $this->status === SecurityShiftStatus::Active
            && $this->next_report_due_at
            && ($at ?? now())->greaterThanOrEqualTo($this->next_report_due_at);
    }
}
