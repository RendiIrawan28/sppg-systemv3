<?php

namespace App\Models;

use App\Enums\NutritionRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NutritionRequirementPlan extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'plan_number',
        'requirement_date',
        'menu_id',
        'field_distribution_plan_id',
        'beneficiary_period_id',
        'menu_cycle_day_id',
        'source_type',
        'requirement_type',
        'original_requirement_plan_id',
        'menu_day_revision_request_id',
        'adjustment_generated_at',
        'adjustment_notes',
        'total_portions',
        'effective_portions',
        'portion_breakdown',
        'buffer_percent',
        'total_items',
        'total_weight_kg',
        'status',
        'notes',
        'revision_notes',
        'created_by',
        'submitted_by',
        'approved_by',
        'generated_at',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'requirement_date' => 'date',
            'original_requirement_plan_id' => 'integer',
            'menu_day_revision_request_id' => 'integer',
            'adjustment_generated_at' => 'datetime',
            'total_portions' => 'integer',
            'effective_portions' => 'decimal:4',
            'portion_breakdown' => 'array',
            'buffer_percent' => 'decimal:2',
            'total_items' => 'integer',
            'total_weight_kg' => 'decimal:3',
            'status' => NutritionRecordStatus::class,
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            $plan->uuid ??= (string) Str::uuid();
            $plan->status ??= NutritionRecordStatus::Draft;

            if (blank($plan->plan_number)) {
                $year = $plan->requirement_date?->format('Y') ?? now()->format('Y');
                $sequence = (int) self::query()
                    ->where('sppg_unit_id', $plan->sppg_unit_id)
                    ->whereYear('requirement_date', $year)
                    ->withTrashed()
                    ->count() + 1;

                $plan->plan_number = sprintf('KBG/%s/%04d', $year, $sequence);
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class);
    }

    public function beneficiaryPeriod(): BelongsTo { return $this->belongsTo(BeneficiaryPeriod::class); }
    public function menuCycleDay(): BelongsTo { return $this->belongsTo(MenuCycleDay::class); }

    public function originalRequirementPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_requirement_plan_id');
    }

    public function menuDayRevisionRequest(): BelongsTo
    {
        return $this->belongsTo(MenuDayRevisionRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NutritionRequirementItem::class);
    }

    public function procurementRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProcurementRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }


    public function getPortionBreakdownSummaryAttribute(): string
    {
        $rows = collect($this->portion_breakdown ?? []);

        if ($rows->isEmpty()) {
            return '-';
        }

        return $rows->map(function (array $row): string {
            $name = $row['name'] ?? $row['code'] ?? 'Kelompok';
            $actual = number_format((float) ($row['master_portions'] ?? $row['actual_portions'] ?? 0), 0, ',', '.');
            $multiplier = number_format((float) ($row['portion_multiplier'] ?? 1), 2, ',', '.');
            $effective = number_format((float) ($row['effective_portions'] ?? 0), 2, ',', '.');
            $portion = strtoupper((string) ($row['portion_size'] ?? '-'));
            $audience = strtoupper(str_replace('_', ' ', (string) ($row['menu_audience'] ?? '-')));

            return "{$name} | {$audience} | {$portion} | {$actual} × {$multiplier} = {$effective} porsi efektif";
        })->implode("\n");
    }

    public function isEditable(): bool
    {
        return ($this->status ?? NutritionRecordStatus::Draft)->isEditable();
    }
}
