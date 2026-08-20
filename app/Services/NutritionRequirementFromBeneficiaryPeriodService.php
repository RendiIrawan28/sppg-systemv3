<?php

namespace App\Services;

use App\Enums\NutritionRecordStatus;
use App\Models\BeneficiaryPeriod;
use App\Models\MenuCycleDay;
use App\Models\NutritionRequirementPlan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class NutritionRequirementFromBeneficiaryPeriodService
{
    public function __construct(
        private readonly NutritionRequirementCalculator $calculator,
        private readonly ProcurementRequestService $procurement,
    ) {}

    public function generate(MenuCycleDay $menuDay, User $actor, ?float $bufferPercent = null): NutritionRequirementPlan
    {
        $menuDay->loadMissing(['cycle', 'menu']);
        $cycle = $menuDay->cycle;
        $menu = $menuDay->menu;

        if (! $cycle || ! $menu || ! $menuDay->service_date) {
            throw new DomainException('Hari pelayanan harus memiliki siklus, tanggal, dan menu final.');
        }
        if ((int) $cycle->sppg_unit_id !== (int) $menu->sppg_unit_id) {
            throw new DomainException('Siklus dan menu berasal dari Unit SPPG yang berbeda.');
        }
        if (! in_array($cycle->status, [NutritionRecordStatus::Approved, NutritionRecordStatus::Active], true)) {
            throw new DomainException('Kebutuhan hanya dapat dihitung dari siklus menu yang disetujui atau aktif.');
        }

        $period = $this->resolvePeriod($menuDay);
        $masterTotal = $this->masterTotal($period);
        if ($masterTotal <= 0) {
            throw new DomainException('Periode penerima tidak memiliki jumlah master aktif.');
        }

        $requirement = DB::transaction(function () use ($menuDay, $cycle, $menu, $period, $actor, $bufferPercent, $masterTotal): NutritionRequirementPlan {
            $requirement = NutritionRequirementPlan::query()
                ->where('sppg_unit_id', $cycle->sppg_unit_id)
                ->where('menu_cycle_day_id', $menuDay->getKey())
                ->where('source_type', 'beneficiary_period_master')
                ->lockForUpdate()
                ->first() ?? new NutritionRequirementPlan();

            if ($requirement->exists && ! $requirement->isEditable()) {
                throw new DomainException('Kebutuhan sudah diajukan/disetujui sehingga tidak dapat dihitung ulang.');
            }

            $requirement->forceFill([
                'sppg_unit_id' => $cycle->sppg_unit_id,
                'requirement_date' => $menuDay->service_date,
                'menu_id' => $menu->getKey(),
                'menu_cycle_day_id' => $menuDay->getKey(),
                'beneficiary_period_id' => $period->getKey(),
                'field_distribution_plan_id' => null,
                'source_type' => 'beneficiary_period_master',
                'total_portions' => $masterTotal,
                'buffer_percent' => max(0, min(100, $bufferPercent ?? (float) $cycle->buffer_percent)),
                'notes' => "Sumber: Master Penerima {$period->code} untuk hari menu {$menuDay->day_number}.",
                'created_by' => $requirement->exists ? $requirement->created_by : $actor->getKey(),
            ])->save();

            return $requirement->refresh();
        });

        $this->calculator->generate($requirement);
        $this->procurement->createOrSynchronizeDraft($requirement->refresh(), $actor);

        return $requirement->refresh();
    }

    public function resolvePeriod(MenuCycleDay $menuDay): BeneficiaryPeriod
    {
        $menuDay->loadMissing('cycle');
        $cycle = $menuDay->cycle;
        $date = $menuDay->service_date;

        if ($cycle?->beneficiary_period_id) {
            $period = BeneficiaryPeriod::query()
                ->whereKey($cycle->beneficiary_period_id)
                ->where('sppg_unit_id', $cycle->sppg_unit_id)
                ->whereIn('status', ['active', 'approved'])
                ->containingDate($date)
                ->first();
            if ($period) {
                return $period;
            }
        }

        return BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $cycle->sppg_unit_id)
            ->whereIn('status', ['active', 'approved'])
            ->containingDate($date)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->firstOr(function (): never {
                throw new DomainException('Tidak ada Periode Penerima aktif/disetujui untuk tanggal pelayanan ini.');
            });
    }

    private function masterTotal(BeneficiaryPeriod $period): int
    {
        $period->loadMissing(['destinations.categoryTotals', 'destinations.members']);

        return (int) $period->destinations
            ->where('is_active', true)
            ->sum(function ($destination): int {
                if ($destination->categoryTotals->isNotEmpty()) {
                    return (int) $destination->categoryTotals->sum('total_beneficiaries');
                }

                return $destination->members->where('is_active', true)->count();
            });
    }
}
