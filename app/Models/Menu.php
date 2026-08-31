<?php

namespace App\Models;

use App\Enums\MenuStatus;
use App\Enums\NutritionRecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'source_menu_id',
        'snapshot_cycle_day_id',
        'is_cycle_snapshot',
        'snapshot_version',
        'snapshot_created_at',
        'snapshot_payload',
        'code',
        'name',
        'service_date',
        'meal_type',
        'planned_portions',
        'status',
        'revision_number',
        'created_by',
        'submitted_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'last_revision_submitted_at',
        'last_revision_approved_at',
        'notes',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'planned_portions' => 'integer',
            'revision_number' => 'integer',
            'is_cycle_snapshot' => 'boolean',
            'snapshot_version' => 'integer',
            'snapshot_created_at' => 'datetime',
            'snapshot_payload' => 'array',
            'status' => MenuStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_revision_submitted_at' => 'datetime',
            'last_revision_approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $menu): void {
            // Modul Ahli Gizi V3 hanya memakai menu basah / makan utama.
            $menu->meal_type = 'lunch';
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function sourceMenu(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_menu_id');
    }

    public function derivedSnapshots(): HasMany
    {
        return $this->hasMany(self::class, 'source_menu_id');
    }

    public function snapshotDay(): BelongsTo
    {
        return $this->belongsTo(MenuCycleDay::class, 'snapshot_cycle_day_id');
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

    public function categoryTargets(): HasMany
    {
        return $this->hasMany(MenuCategoryTarget::class, 'menu_id')->orderBy('id');
    }

    public function targetCategories(): BelongsToMany
    {
        return $this->belongsToMany(BeneficiaryCategory::class, 'menu_beneficiary_category')
            ->withPivot('portion_multiplier')
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function nutritionSummaries(): HasMany
    {
        return $this->hasMany(MenuNutritionSummary::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MenuApproval::class)->latest('id');
    }

    public function allergenSummaries(): HasMany
    {
        return $this->hasMany(MenuAllergenSummary::class);
    }

    public function allergenSubstitutions(): HasMany
    {
        return $this->hasMany(MenuAllergenSubstitution::class);
    }

    public function cycleDays(): HasMany
    {
        return $this->hasMany(MenuCycleDay::class);
    }

    public function cycleDayVariants(): HasMany
    {
        return $this->hasMany(MenuCycleDayVariant::class);
    }

    public function revisionRequestsAsOriginal(): HasMany
    {
        return $this->hasMany(MenuDayRevisionRequest::class, 'original_menu_id');
    }

    public function revisionRequests(): HasMany
    {
        return $this->hasMany(MenuDayRevisionRequest::class, 'revision_menu_id');
    }

    public function nutritionRequirementPlans(): HasMany
    {
        return $this->hasMany(NutritionRequirementPlan::class);
    }

    public function acceptanceEvaluations(): HasMany
    {
        return $this->hasMany(MenuAcceptanceEvaluation::class);
    }

    public function nutritionDailyReports(): HasMany
    {
        return $this->hasMany(NutritionDailyReport::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [MenuStatus::Draft, MenuStatus::RevisionRequired], true);
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    public function belongsToActiveCycle(): bool
    {
        return $this->cycleDays()
            ->whereHas('cycle', fn ($query) => $query->where('status', NutritionRecordStatus::Active->value))
            ->exists()
            || $this->revisionRequests()
                ->whereHas('cycle', fn ($query) => $query->where('status', NutritionRecordStatus::Active->value))
                ->exists();
    }

    public function belongsToApprovedOrActiveCycle(): bool
    {
        $statuses = [
            NutritionRecordStatus::Approved->value,
            NutritionRecordStatus::Active->value,
        ];

        return $this->cycleDays()
            ->whereHas('cycle', fn ($query) => $query->whereIn('status', $statuses))
            ->exists()
            || $this->revisionRequests()
                ->whereHas('cycle', fn ($query) => $query->whereIn('status', $statuses))
                ->exists();
    }
}
