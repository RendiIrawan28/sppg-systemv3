<?php

namespace App\Models;

use App\Enums\NutritionRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuAcceptanceEvaluation extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'evaluation_date',
        'menu_id',
        'field_distribution_plan_id',
        'distribution_run_id',
        'location_type',
        'location_id',
        'location_name_snapshot',
        'respondent_count',
        'served_portions',
        'accepted_portions',
        'leftover_portions',
        'color_score',
        'aroma_score',
        'taste_score',
        'texture_score',
        'portion_score',
        'temperature_score',
        'overall_score',
        'acceptance_percent',
        'waste_percent',
        'complaints',
        'corrective_actions',
        'photo_path',
        'status',
        'revision_notes',
        'evaluator_id',
        'submitted_by',
        'approved_by',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'evaluation_date' => 'date',
            'respondent_count' => 'integer',
            'served_portions' => 'integer',
            'accepted_portions' => 'integer',
            'leftover_portions' => 'integer',
            'color_score' => 'integer',
            'aroma_score' => 'integer',
            'taste_score' => 'integer',
            'texture_score' => 'integer',
            'portion_score' => 'integer',
            'temperature_score' => 'integer',
            'overall_score' => 'decimal:2',
            'acceptance_percent' => 'decimal:2',
            'waste_percent' => 'decimal:2',
            'status' => NutritionRecordStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evaluation): void {
            $evaluation->uuid ??= (string) Str::uuid();
            $evaluation->status ??= NutritionRecordStatus::Draft;
        });

        static::saving(function (self $evaluation): void {
            $scores = collect([
                $evaluation->color_score,
                $evaluation->aroma_score,
                $evaluation->taste_score,
                $evaluation->texture_score,
                $evaluation->portion_score,
                $evaluation->temperature_score,
            ])->filter(fn (mixed $score): bool => $score !== null);

            $evaluation->overall_score = $scores->isNotEmpty()
                ? round((float) $scores->average(), 2)
                : null;

            $served = max(0, (int) $evaluation->served_portions);
            $accepted = min($served, max(0, (int) $evaluation->accepted_portions));
            $leftover = min($served, max(0, (int) $evaluation->leftover_portions));

            $evaluation->acceptance_percent = $served > 0
                ? round(($accepted / $served) * 100, 2)
                : null;

            $evaluation->waste_percent = $served > 0
                ? round(($leftover / $served) * 100, 2)
                : null;
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

    public function distributionRun(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return ($this->status ?? NutritionRecordStatus::Draft)->isEditable();
    }
}
