<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProcessingBatch extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'field_distribution_plan_id',
        'menu_cycle_day_id',
        'batch_number',
        'batch_year',
        'sequence_number',
        'production_date',
        'service_date',
        'is_rapel',
        'batch_type',
        'menu_id',
        'menu_name_snapshot',
        'product_name',
        'target_output_quantity',
        'target_output_unit',
        'actual_output_quantity',
        'actual_output_unit',
        'started_at',
        'completed_at',
        'duration_minutes',
        'state',
        'petugas_id',
        'petugas_name_snapshot',
        'notes',
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
            'production_date' => 'date',
            'service_date' => 'date',
            'is_rapel' => 'boolean',
            'target_output_quantity' => 'decimal:3',
            'actual_output_quantity' => 'decimal:3',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'batch_year' => 'integer',
            'sequence_number' => 'integer',
            'state' => ProcessingBatchState::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'division_approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->uuid ??= (string) Str::uuid();
            $batch->state ??= ProcessingBatchState::Planned;
            $batch->status ??= OperationalReportStatus::Draft;
            $batch->assignBatchSequence();
            $batch->batch_number ??= $batch->buildBatchNumber();
        });

        static::saving(function (self $batch): void {
            if ($batch->started_at && $batch->completed_at) {
                $batch->duration_minutes = max(
                    0,
                    $batch->started_at->diffInMinutes($batch->completed_at),
                );
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class);
    }

    public function menuCycleDay(): BelongsTo
    {
        return $this->belongsTo(MenuCycleDay::class);
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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

    public function materialUsages(): HasMany
    {
        return $this->hasMany(ProcessingMaterialUsage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function temperatureLogs(): HasMany
    {
        return $this->hasMany(ProcessingTemperatureLog::class)
            ->orderBy('checked_at')
            ->orderBy('id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(ProcessingDocumentation::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProcessingReturn::class)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ProcessingHistory::class)->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('production_date', $date);
    }

    public function isReportEditable(): bool
    {
        return in_array($this->status, [
            OperationalReportStatus::Draft,
            OperationalReportStatus::RevisionRequired,
        ], true);
    }

    public function isOperationalInputEditable(): bool
    {
        return $this->isReportEditable()
            && $this->state === ProcessingBatchState::InProgress;
    }

    public function recalculateTotals(): void
    {
        $outputs = $this->documentations()
            ->where('documentation_type', 'finished_output')
            ->whereNotNull('output_quantity')
            ->get(['output_quantity', 'output_unit']);

        if ($outputs->isEmpty()) {
            $this->forceFill([
                'actual_output_quantity' => 0,
                'actual_output_unit' => null,
            ])->saveQuietly();

            return;
        }

        $units = $outputs->pluck('output_unit')
            ->filter(fn ($unit): bool => filled($unit))
            ->map(fn ($unit): string => trim((string) $unit))
            ->unique()
            ->values();

        $this->forceFill([
            'actual_output_quantity' => $outputs->sum(fn ($output): float => (float) $output->output_quantity),
            'actual_output_unit' => $units->count() === 1 ? $units->first() : 'campuran',
        ])->saveQuietly();
    }

    public function canBeSubmitted(): bool
    {
        return $this->isReportEditable()
            && $this->state === ProcessingBatchState::Completed;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === OperationalReportStatus::Draft
            && $this->state === ProcessingBatchState::Planned;
    }

    private function assignBatchSequence(): void
    {
        $date = $this->production_date
            ? Carbon::parse($this->production_date)
            : now();

        $this->batch_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('batch_year', $this->batch_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildBatchNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'PRD/%s/%d/%04d',
            strtoupper((string) $unitCode),
            $this->batch_year,
            $this->sequence_number,
        );
    }

    public function preparationOutputWithdrawals(): HasMany
    {
        return $this->hasMany(PreparationOutputWithdrawal::class);
    }
}
