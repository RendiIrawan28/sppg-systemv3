<?php

namespace App\Models;

use App\Enums\SecurityShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SecurityShift extends Model
{
    use HasFactory;

    public const DURATION_HOURS = 12;

    public const REPORT_INTERVAL_HOURS = 3;

    public const EXPECTED_REPORTS = 4;

    public const REPORT_GRACE_MINUTES = 15;

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
        if ($this->status !== SecurityShiftStatus::Active) {
            return null;
        }

        $eligible = $this->eligibleReportSequence();
        $completed = $this->completedReportSequences();

        if ($eligible === null) {
            return $completed->contains(1) ? 2 : 1;
        }

        if (! $completed->contains($eligible)) {
            return $eligible;
        }

        $next = $eligible + 1;

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
        $at ??= now();

        return $this->status === SecurityShiftStatus::Active
            && $this->next_report_due_at
            && $at->greaterThanOrEqualTo($this->next_report_due_at)
            && $this->reportSequenceDueAt($at) === $this->next_report_sequence;
    }

    public function reportingDeadline(): Carbon
    {
        return $this->scheduled_end_at->copy()->addMinutes(self::REPORT_GRACE_MINUTES);
    }

    public function shouldExpire(?Carbon $at = null): bool
    {
        return $this->status === SecurityShiftStatus::Active
            && ($at ?? now())->greaterThanOrEqualTo($this->reportingDeadline());
    }

    public function eligibleReportSequence(?Carbon $at = null): ?int
    {
        $at ??= now();
        if ($at->lessThan($this->started_at->copy()->addHours(self::REPORT_INTERVAL_HOURS))) {
            return null;
        }

        $elapsedSeconds = max(0, $this->started_at->diffInSeconds($at, false));
        $sequence = (int) floor($elapsedSeconds / (self::REPORT_INTERVAL_HOURS * 3600));

        return min($this->reports_expected, max(1, $sequence));
    }

    public function reportSequenceDueAt(?Carbon $at = null): ?int
    {
        $at ??= now();
        if ($this->shouldExpire($at)) {
            return null;
        }

        $eligible = $this->eligibleReportSequence($at);
        if ($eligible === null || $this->completedReportSequences()->contains($eligible)) {
            return null;
        }

        return $eligible;
    }

    /** @return array<int, int> */
    public function missedReportSequences(?Carbon $at = null): array
    {
        $at ??= now();
        $completed = $this->completedReportSequences();

        return collect(range(1, $this->reports_expected))
            ->reject(fn (int $sequence): bool => $completed->contains($sequence))
            ->filter(function (int $sequence) use ($at): bool {
                $deadline = $sequence < $this->reports_expected
                    ? $this->started_at->copy()->addHours(self::REPORT_INTERVAL_HOURS * ($sequence + 1))
                    : $this->reportingDeadline();

                return $at->greaterThanOrEqualTo($deadline);
            })
            ->values()
            ->all();
    }

    private function completedReportSequences(): Collection
    {
        $reports = $this->relationLoaded('reports') ? $this->reports : $this->reports()->get();

        return $reports->pluck('sequence_number')->map(fn ($value): int => (int) $value);
    }
}
