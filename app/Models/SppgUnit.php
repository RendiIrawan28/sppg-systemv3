<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SppgUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'slug',
        'address',
        'phone',
        'email',
        'head_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function posyandus(): HasMany
    {
        return $this->hasMany(Posyandu::class);
    }

    public function beneficiaryCategories(): HasMany
    {
        return $this->hasMany(
            BeneficiaryCategory::class
        );
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function beneficiaryImports(): HasMany
    {
        return $this->hasMany(
            BeneficiaryImport::class
        );
    }
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function nutritionStandards(): HasMany
    {
        return $this->hasMany(
            NutritionStandard::class
        );
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }
    public function preparationMaterialInspections(): HasMany
    {
        return $this->hasMany(
            \App\Models\PreparationMaterialInspection::class
        );
    }
    public function wasteHandoverReports(): HasMany
    {
        return $this->hasMany(WasteHandoverReport::class);
    }
    public function processingBatches(): HasMany
    {
        return $this->hasMany(
            \App\Models\ProcessingBatch::class
        );
    }
    public function portioningSessions(): HasMany
    {
        return $this->hasMany(
            \App\Models\PortioningSession::class
        );
    }
    public function distributionRuns(): HasMany
    {
        return $this->hasMany(\App\Models\DistributionRun::class);
    }
    public function washingSessions(): HasMany
    {
        return $this->hasMany(\App\Models\WashingSession::class);
    }
    public function fieldDistributionPlans(): HasMany
    {
        return $this->hasMany(
            \App\Models\FieldDistributionPlan::class
        );
    }

    public function fieldDailyReports(): HasMany
    {
        return $this->hasMany(
            \App\Models\FieldDailyReport::class
        );
    }

    public function fieldIncidents(): HasMany
    {
        return $this->hasMany(
            \App\Models\FieldIncident::class
        );
    }
    public function portionStandards(): HasMany
    {
        return $this->hasMany(\App\Models\PortionStandard::class);
    }

    public function menuCycles(): HasMany
    {
        return $this->hasMany(\App\Models\MenuCycle::class);
    }

    public function serviceHolidays(): HasMany
    {
        return $this->hasMany(\App\Models\ServiceHoliday::class);
    }

    public function menuAllergenSubstitutions(): HasMany
    {
        return $this->hasMany(\App\Models\MenuAllergenSubstitution::class);
    }

    public function nutritionRequirementPlans(): HasMany
    {
        return $this->hasMany(\App\Models\NutritionRequirementPlan::class);
    }

    public function menuAcceptanceEvaluations(): HasMany
    {
        return $this->hasMany(\App\Models\MenuAcceptanceEvaluation::class);
    }

    public function nutritionDailyReports(): HasMany
    {
        return $this->hasMany(\App\Models\NutritionDailyReport::class);
    }

    public function nutritionWorkflowHistories(): HasMany
    {
        return $this->hasMany(\App\Models\NutritionWorkflowHistory::class);
    }
    public function headApprovalTasks(): HasMany
    {
        return $this->hasMany(\App\Models\HeadApprovalTask::class);
    }

    public function headExecutiveReports(): HasMany
    {
        return $this->hasMany(\App\Models\HeadExecutiveReport::class);
    }


    public function beneficiaryPeriods(): HasMany
    {
        return $this->hasMany(\App\Models\BeneficiaryPeriod::class);
    }

    public function dailyBeneficiaryConfirmations(): HasMany
    {
        return $this->hasMany(\App\Models\DailyBeneficiaryConfirmation::class);
    }
}
