<?php

namespace App\Models;

use App\Enums\DistributionRunState;
use App\Enums\OperationalReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DistributionRun extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'portioning_session_id',
        'field_distribution_plan_id',
        'run_number',
        'run_year',
        'sequence_number',
        'distribution_date',
        'menu_name_snapshot',
        'planned_small_portions',
        'planned_large_portions',
        'loaded_small_portions',
        'loaded_large_portions',
        'delivered_small_portions',
        'delivered_large_portions',
        'returned_small_portions',
        'returned_large_portions',
        'planned_departure_at',
        'actual_departure_at',
        'returned_at',
        'duration_minutes',
        'state',
        'vehicle_name',
        'vehicle_plate',
        'driver_name',
        'departure_temperature_celsius',
        'petugas_id',
        'petugas_name_snapshot',
        'notes',
        'status',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'review_notes',
        'source_system',
        'legacy_id',
        'legacy_sheet_name',
        'legacy_created_at',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'planned_small_portions' => 'integer',
            'planned_large_portions' => 'integer',
            'loaded_small_portions' => 'integer',
            'loaded_large_portions' => 'integer',
            'delivered_small_portions' => 'integer',
            'delivered_large_portions' => 'integer',
            'returned_small_portions' => 'integer',
            'returned_large_portions' => 'integer',
            'planned_departure_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'returned_at' => 'datetime',
            'duration_minutes' => 'integer',
            'run_year' => 'integer',
            'sequence_number' => 'integer',
            'departure_temperature_celsius' => 'decimal:2',
            'state' => DistributionRunState::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
            $run->state ??= DistributionRunState::Planned;
            $run->status ??= OperationalReportStatus::Draft;
            $run->source_system ??= 'laravel_v2';
            $run->assignSequence();
            $run->run_number ??= $run->buildRunNumber();
        });

        static::saving(function (self $run): void {
            if ($run->actual_departure_at && $run->returned_at) {
                $run->duration_minutes = max(
                    0,
                    $run->actual_departure_at->diffInMinutes($run->returned_at),
                );
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function portioningSession(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class);
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

    public function stops(): HasMany
    {
        return $this->hasMany(DistributionStop::class)
            ->orderBy('sequence_order')
            ->orderBy('id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(DistributionDocumentation::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(DistributionIncident::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DistributionHistory::class)->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('distribution_date', $date);
    }

    public function getPlannedTotalAttribute(): int
    {
        return (int) $this->planned_small_portions + (int) $this->planned_large_portions;
    }

    public function getLoadedTotalAttribute(): int
    {
        return (int) $this->loaded_small_portions + (int) $this->loaded_large_portions;
    }

    public function getDeliveredTotalAttribute(): int
    {
        return (int) $this->delivered_small_portions + (int) $this->delivered_large_portions;
    }

    public function getReturnedTotalAttribute(): int
    {
        return (int) $this->returned_small_portions + (int) $this->returned_large_portions;
    }

    public function getUnaccountedTotalAttribute(): int
    {
        return $this->loaded_total - $this->delivered_total - $this->returned_total;
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
            && $this->state === DistributionRunState::Returned;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === OperationalReportStatus::Draft
            && $this->state === DistributionRunState::Planned;
    }

    public function recalculateTotals(): void
    {
        $stops = $this->stops()->get();

        $this->updateQuietly([
            'planned_small_portions' => (int) $stops->sum('small_portions'),
            'planned_large_portions' => (int) $stops->sum('large_portions'),
            'delivered_small_portions' => (int) $stops->sum('delivered_small_portions'),
            'delivered_large_portions' => (int) $stops->sum('delivered_large_portions'),
            'returned_small_portions' => (int) $stops->sum('returned_small_portions'),
            'returned_large_portions' => (int) $stops->sum('returned_large_portions'),
        ]);

        $this->refresh();
    }

    private function assignSequence(): void
    {
        $date = $this->distribution_date
            ? Carbon::parse($this->distribution_date)
            : now();

        $this->run_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('run_year', $this->run_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildRunNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'DST/%s/%d/%04d',
            strtoupper((string) $unitCode),
            $this->run_year,
            $this->sequence_number,
        );
    }
    public function washingSessions(): HasMany
    {
        return $this->hasMany(\App\Models\WashingSession::class);
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\FieldDistributionPlan::class
        );
    }
    public function menuAcceptanceEvaluations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MenuAcceptanceEvaluation::class);
    }
}
