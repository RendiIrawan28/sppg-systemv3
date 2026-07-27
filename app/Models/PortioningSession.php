<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PortioningSession extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'processing_batch_id',
        'field_distribution_plan_id',
        'session_number',
        'session_year',
        'sequence_number',
        'portioning_date',
        'menu_name_snapshot',
        'target_small_portions',
        'target_large_portions',
        'actual_small_portions',
        'actual_large_portions',
        'started_at',
        'completed_at',
        'duration_minutes',
        'state',
        'petugas_id',
        'petugas_name_snapshot',
        'notes',
        'leftover_mode',
        'status',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'division_approved_by',
        'division_approved_at',
        'verified_by',
        'verified_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'portioning_date' => 'date',
            'target_small_portions' => 'integer',
            'target_large_portions' => 'integer',
            'actual_small_portions' => 'integer',
            'actual_large_portions' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'session_year' => 'integer',
            'sequence_number' => 'integer',
            'state' => PortioningSessionState::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'division_approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
            $session->state ??= PortioningSessionState::Planned;
            $session->status ??= OperationalReportStatus::Draft;
            $session->assignSequence();
            $session->session_number ??= $session->buildSessionNumber();
        });

        static::saving(function (self $session): void {
            if ($session->started_at && $session->completed_at) {
                $session->duration_minutes = max(
                    0,
                    $session->started_at->diffInMinutes($session->completed_at),
                );
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function processingBatch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function divisionApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'division_approved_by');
    }

    public function routeAllocations(): HasMany
    {
        return $this->hasMany(PortioningRouteAllocation::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function routeRecords(): HasMany
    {
        return $this->hasMany(PortioningRouteRecord::class)
            ->orderBy('completed_at')
            ->orderBy('id');
    }

    public function leftoverRecords(): HasMany
    {
        return $this->hasMany(PortioningLeftoverRecord::class)
            ->orderBy('checked_at')
            ->orderBy('id');
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(PortioningSupply::class)->orderBy('sort_order')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PortioningHistory::class)->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('portioning_date', $date);
    }

    public function getTargetTotalAttribute(): int
    {
        return (int) $this->target_small_portions + (int) $this->target_large_portions;
    }

    public function getActualTotalAttribute(): int
    {
        return (int) $this->actual_small_portions + (int) $this->actual_large_portions;
    }

    public function getDifferenceTotalAttribute(): int
    {
        return $this->actual_total - $this->target_total;
    }

    public function isReportEditable(): bool
    {
        return in_array($this->status, [
            OperationalReportStatus::Draft,
            OperationalReportStatus::RevisionRequired,
        ], true);
    }

    public function canBeSubmitted(): bool
    {
        return $this->isReportEditable()
            && $this->state === PortioningSessionState::Completed;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === OperationalReportStatus::Draft
            && $this->state === PortioningSessionState::Planned;
    }

    public function recalculateTotals(): void
    {
        $routes = $this->routeAllocations()->get();
        $records = $this->routeRecords()->get();

        $this->updateQuietly([
            'target_small_portions' => (int) $routes->sum('target_small_portions'),
            'target_large_portions' => (int) $routes->sum('target_large_portions'),
            'actual_small_portions' => (int) $records->sum('small_portions'),
            'actual_large_portions' => (int) $records->sum('large_portions'),
        ]);

        $this->refresh();
    }

    private function assignSequence(): void
    {
        $date = $this->portioning_date
            ? Carbon::parse($this->portioning_date)
            : now();

        $this->session_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('session_year', $this->session_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildSessionNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'POR/%s/%d/%04d',
            strtoupper((string) $unitCode),
            $this->session_year,
            $this->sequence_number,
        );
    }

    public function distributionRuns(): HasMany
    {
        return $this->hasMany(DistributionRun::class);
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(
            FieldDistributionPlan::class
        );
    }
}
