<?php

namespace App\Services;

use App\Enums\FieldDistributionPlanStatus;
use App\Models\FieldDistributionPlan;
use App\Models\NutritionRequirementPlan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class NutritionRequirementFromFieldPlanService
{
    public function __construct(
        private readonly NutritionRequirementCalculator $calculator,
        private readonly ProcurementRequestService $procurement,
    ) {
    }

    public function generate(FieldDistributionPlan $fieldPlan, User $actor, float $bufferPercent = 3.0): NutritionRequirementPlan
    {
        $fieldPlan->refresh();
        $fieldPlan->recalculateTotals();
        $fieldPlan->loadMissing('destinations.recipientGroups');

        if (! in_array($fieldPlan->status, [
            FieldDistributionPlanStatus::Approved,
            FieldDistributionPlanStatus::Activated,
        ], true)) {
            throw new DomainException('Kebutuhan bahan hanya dapat dibuat dari rencana H-3 yang sudah disetujui.');
        }

        if (! $fieldPlan->menu_id) {
            throw new DomainException('Rencana H-3 belum memiliki menu.');
        }

        $actualPortions = (int) $fieldPlan->destinations
            ->flatMap->recipientGroups
            ->sum(fn ($group): int => (int) ($group->total_portions ?: $group->confirmed_beneficiaries));

        if ($actualPortions <= 0) {
            throw new DomainException('Rencana H-3 belum memiliki rincian jumlah aktual per kelompok penerima.');
        }

        $requirement = DB::transaction(function () use ($fieldPlan, $actor, $bufferPercent, $actualPortions): NutritionRequirementPlan {
            $requirement = NutritionRequirementPlan::query()
                ->where('field_distribution_plan_id', $fieldPlan->getKey())
                ->first();

            $requirement ??= new NutritionRequirementPlan();

            if (! $requirement->isEditable()) {
                throw new DomainException('Rencana kebutuhan bahan sudah diajukan/disetujui sehingga tidak dapat dihitung ulang.');
            }

            $requirement->forceFill([
                'sppg_unit_id' => $fieldPlan->sppg_unit_id,
                'requirement_date' => $fieldPlan->distribution_date,
                'menu_id' => $fieldPlan->menu_id,
                'field_distribution_plan_id' => $fieldPlan->getKey(),
                'total_portions' => $actualPortions,
                'buffer_percent' => max(0, min(100, $bufferPercent)),
                'notes' => trim("Dibuat dari rencana distribusi {$fieldPlan->plan_number}.\n".($requirement->notes ?? '')),
                'created_by' => $requirement->exists ? $requirement->created_by : $actor->getKey(),
            ])->save();

            return $requirement->refresh();
        });

        $this->calculator->generate($requirement);
        $this->procurement->createOrSynchronizeDraft($requirement->refresh(), $actor);

        return $requirement->refresh();
    }
}
