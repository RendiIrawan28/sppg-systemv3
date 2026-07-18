<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'preparation_material_handover_id',
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
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->uuid ??= (string) Str::uuid();
            $batch->state ??= ProcessingBatchState::Planned;
            $batch->status ??= OperationalReportStatus::Draft;
            $batch->source_system ??= 'laravel_v2';
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

    public function preparationMaterialHandover(): BelongsTo
    {
        return $this->belongsTo(PreparationMaterialHandover::class);
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

    public function destinations(): HasMany
    {
        return $this->hasMany(ProcessingBatchDestination::class)
            ->orderBy('sequence_order')
            ->orderBy('id');
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

    public function steps(): HasMany
    {
        return $this->hasMany(ProcessingStep::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(ProcessingDocumentation::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function deviations(): HasMany
    {
        return $this->hasMany(ProcessingDeviation::class)
            ->orderBy('detected_at')
            ->orderBy('id');
    }

    public function handover(): HasOne
    {
        return $this->hasOne(ProcessingHandover::class);
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

    public function canBeSubmitted(): bool
    {
        return $this->isReportEditable()
            && $this->state === ProcessingBatchState::HandedOver;
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
}
