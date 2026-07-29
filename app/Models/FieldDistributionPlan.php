<?php

namespace App\Models;

use App\Enums\FieldDistributionPlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FieldDistributionPlan extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'beneficiary_period_id',
        'menu_cycle_day_id',
        'plan_number',
        'plan_year',
        'sequence_number',
        'distribution_date',
        'service_date',
        'production_date',
        'is_rapel',
        'menu_id',
        'menu_name_snapshot',
        'meal_type',
        'shift',
        'planned_beneficiaries',
        'confirmed_beneficiaries',
        'planned_small_portions',
        'planned_large_portions',
        'planned_total_portions',
        'destination_count',
        'confirmation_deadline_at',
        'general_notes',
        'status',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'activated_by',
        'activated_at',
        'completed_at',
        'review_notes',
        'processing_batch_id',
        'portioning_session_id',
        'distribution_run_id',
        'source_system',
        'actual_data_synced_at',
        'actual_data_synced_by',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'service_date' => 'date',
            'production_date' => 'date',
            'is_rapel' => 'boolean',
            'beneficiary_period_id' => 'integer',
            'plan_year' => 'integer',
            'sequence_number' => 'integer',
            'planned_beneficiaries' => 'integer',
            'confirmed_beneficiaries' => 'integer',
            'planned_small_portions' => 'integer',
            'planned_large_portions' => 'integer',
            'planned_total_portions' => 'integer',
            'destination_count' => 'integer',
            'confirmation_deadline_at' => 'datetime',
            'status' => FieldDistributionPlanStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'actual_data_synced_at' => 'datetime',
            'actual_data_synced_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            $plan->uuid ??= (string) Str::uuid();
            $plan->status ??= FieldDistributionPlanStatus::Draft;
            $plan->source_system ??= 'laravel_v2';
            $plan->assignSequence();
            $plan->plan_number ??= $plan->buildPlanNumber();
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function menuCycleDay(): BelongsTo
    {
        return $this->belongsTo(MenuCycleDay::class);
    }

    public function beneficiaryPeriod(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class);
    }

    public function actualDataSynchronizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actual_data_synced_by');
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(FieldDistributionPlanDestination::class)
            ->orderBy('sequence_order')
            ->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FieldDistributionPlanHistory::class)->latest();
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(FieldDailyReport::class);
    }

    public function processingBatch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function portioningSession(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class);
    }

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }

    public function distributionRuns(): HasMany
    {
        return $this->hasMany(DistributionRun::class)
            ->orderBy('route_name')
            ->orderBy('id');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('distribution_date', $date);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            FieldDistributionPlanStatus::Draft,
            FieldDistributionPlanStatus::RevisionRequired,
        ], true);
    }

    public function canBeSubmitted(): bool
    {
        return $this->isEditable() && $this->destinations()->exists();
    }

    public function canBeDeleted(): bool
    {
        return $this->status === FieldDistributionPlanStatus::Draft;
    }

    public function recalculateTotals(): void
    {
        $destinations = $this->destinations()->get();

        $this->updateQuietly([
            'planned_beneficiaries' => (int) $destinations->sum('registered_beneficiaries'),
            'confirmed_beneficiaries' => (int) $destinations->sum('confirmed_beneficiaries'),
            'planned_small_portions' => (int) $destinations->sum('small_portions'),
            'planned_large_portions' => (int) $destinations->sum('large_portions'),
            'planned_total_portions' => (int) $destinations->sum('total_portions'),
            'destination_count' => $destinations
                ->filter(fn ($destination): bool => (int) $destination->total_portions > 0)
                ->count(),
        ]);

        $this->refresh();
    }

    private function assignSequence(): void
    {
        $date = $this->distribution_date
            ? Carbon::parse($this->distribution_date)
            : now();

        $this->plan_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('plan_year', $this->plan_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildPlanNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'RDL/%s/%d/%04d',
            strtoupper((string) $unitCode),
            $this->plan_year,
            $this->sequence_number,
        );
    }
    public function nutritionRequirementPlans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\NutritionRequirementPlan::class);
    }

    public function menuAcceptanceEvaluations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MenuAcceptanceEvaluation::class);
    }
}
