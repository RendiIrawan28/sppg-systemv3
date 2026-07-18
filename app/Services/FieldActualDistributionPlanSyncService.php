<?php

namespace App\Services;

use App\Enums\FieldDistributionPlanStatus;
use App\Enums\NutritionRecordStatus;
use App\Models\DailyBeneficiaryConfirmation;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FieldActualDistributionPlanSyncService
{
    /**
     * Sinkron seluruh hari menu dalam siklus dengan konfirmasi aktual Asisten Lapangan.
     * Rencana distribusi hanya dibuat/diperbarui jika sudah ada konfirmasi aktual.
     *
     * @return array{created:int,updated:int,skipped:int,waiting_actual:int}
     */
    public function syncForMenuCycle(MenuCycle $cycle, ?User $actor = null): array
    {
        $cycle->loadMissing('days.menu');

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'waiting_actual' => 0,
        ];

        foreach ($cycle->days as $day) {
            $result = $this->syncForMenuCycleDay($day, $actor);

            if ($result === null) {
                $summary['waiting_actual']++;
                continue;
            }

            $summary[$result['created'] ? 'created' : 'updated']++;
        }

        return $summary;
    }

    /** @return array{plan:FieldDistributionPlan,created:bool,updated:bool}|null */
    public function syncForMenuCycleDay(MenuCycleDay $day, ?User $actor = null): ?array
    {
        $day->loadMissing('cycle', 'menu');

        if (! $day->service_date || ! $day->cycle) {
            return null;
        }

        $confirmations = $this->confirmedActualData(
            unitId: (int) $day->cycle->sppg_unit_id,
            serviceDate: $day->service_date->toDateString(),
            beneficiaryPeriodId: $day->cycle->beneficiary_period_id ? (int) $day->cycle->beneficiary_period_id : null,
        );

        if ($confirmations->isEmpty()) {
            return null;
        }

        return $this->syncForDate(
            unitId: (int) $day->cycle->sppg_unit_id,
            serviceDate: $day->service_date->toDateString(),
            beneficiaryPeriodId: $day->cycle->beneficiary_period_id ? (int) $day->cycle->beneficiary_period_id : null,
            confirmations: $confirmations,
            day: $day,
            actor: $actor,
        );
    }

    /** @return array{plan:FieldDistributionPlan,created:bool,updated:bool}|null */
    public function syncForConfirmation(DailyBeneficiaryConfirmation $confirmation, ?User $actor = null): ?array
    {
        if (! in_array($confirmation->status, ['confirmed', 'changed'], true)) {
            return null;
        }

        $day = $this->menuDayForDate(
            unitId: (int) $confirmation->sppg_unit_id,
            serviceDate: $confirmation->service_date->toDateString(),
            beneficiaryPeriodId: (int) $confirmation->beneficiary_period_id,
        );

        $confirmations = $this->confirmedActualData(
            unitId: (int) $confirmation->sppg_unit_id,
            serviceDate: $confirmation->service_date->toDateString(),
            beneficiaryPeriodId: (int) $confirmation->beneficiary_period_id,
        );

        if ($confirmations->isEmpty()) {
            return null;
        }

        return $this->syncForDate(
            unitId: (int) $confirmation->sppg_unit_id,
            serviceDate: $confirmation->service_date->toDateString(),
            beneficiaryPeriodId: (int) $confirmation->beneficiary_period_id,
            confirmations: $confirmations,
            day: $day,
            actor: $actor,
        );
    }

    /**
     * @param Collection<int, DailyBeneficiaryConfirmation> $confirmations
     * @return array{plan:FieldDistributionPlan,created:bool,updated:bool}
     */
    private function syncForDate(
        int $unitId,
        string $serviceDate,
        ?int $beneficiaryPeriodId,
        Collection $confirmations,
        ?MenuCycleDay $day,
        ?User $actor = null,
    ): array {
        return DB::transaction(function () use ($unitId, $serviceDate, $beneficiaryPeriodId, $confirmations, $day, $actor): array {
            $plan = $this->findPlan($unitId, $serviceDate, $beneficiaryPeriodId);
            $created = $plan === null;
            $plan ??= new FieldDistributionPlan();

            $planIsMutable = $created || in_array($plan->status, [
                FieldDistributionPlanStatus::Draft,
                FieldDistributionPlanStatus::RevisionRequired,
            ], true);

            $menu = $day?->menu;
            $actual = $this->actualTotals($confirmations);

            $attributes = [
                'sppg_unit_id' => $unitId,
                'beneficiary_period_id' => $beneficiaryPeriodId,
                'distribution_date' => $serviceDate,
                'menu_id' => $menu?->getKey(),
                'menu_name_snapshot' => $menu?->name ?: 'Menunggu menu final',
                'meal_type' => 'lunch',
                'planned_beneficiaries' => $actual['registered'],
                'confirmed_beneficiaries' => $actual['confirmed'],
                'planned_small_portions' => $actual['small'],
                'planned_large_portions' => $actual['large'],
                'planned_total_portions' => $actual['total'],
                'destination_count' => $confirmations->count(),
                'source_system' => 'actual_confirmation',
                'actual_data_synced_at' => now(),
                'actual_data_synced_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ];

            if (Schema::hasColumn('field_distribution_plans', 'menu_cycle_day_id')) {
                $attributes['menu_cycle_day_id'] = $day?->getKey();
            }

            if (Schema::hasColumn('field_distribution_plans', 'service_date')) {
                $attributes['service_date'] = $serviceDate;
            }

            if (Schema::hasColumn('field_distribution_plans', 'production_date')) {
                $attributes['production_date'] = $day?->production_date?->toDateString() ?: $serviceDate;
            }

            if (Schema::hasColumn('field_distribution_plans', 'is_rapel')) {
                $attributes['is_rapel'] = (bool) ($day?->is_rapel ?? false);
            }

            if ($created) {
                $attributes['created_by'] = $actor?->getKey();
                $attributes['status'] = FieldDistributionPlanStatus::Draft;
            }

            $plan->forceFill($attributes)->save();

            if ($planIsMutable) {
                $this->replaceDestinations($plan->refresh(), $confirmations);
            }

            if ($day && Schema::hasColumn('menu_cycle_days', 'field_distribution_plan_id')) {
                $day->forceFill(['field_distribution_plan_id' => $plan->getKey()])->save();
            }

            return [
                'plan' => $plan->refresh(),
                'created' => $created,
                'updated' => ! $created,
            ];
        });
    }

    private function findPlan(int $unitId, string $serviceDate, ?int $beneficiaryPeriodId): ?FieldDistributionPlan
    {
        return FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->when($beneficiaryPeriodId !== null, fn ($query) => $query->where('beneficiary_period_id', $beneficiaryPeriodId))
            ->whereDate('distribution_date', $serviceDate)
            ->whereNotIn('status', [
                FieldDistributionPlanStatus::Completed->value,
                FieldDistributionPlanStatus::Cancelled->value,
            ])
            ->latest('id')
            ->first();
    }

    private function menuDayForDate(int $unitId, string $serviceDate, ?int $beneficiaryPeriodId): ?MenuCycleDay
    {
        return MenuCycleDay::query()
            ->with(['cycle', 'menu'])
            ->whereDate('service_date', $serviceDate)
            ->whereHas('cycle', function ($query) use ($unitId, $beneficiaryPeriodId): void {
                $query->where('sppg_unit_id', $unitId)
                    ->whereIn('status', [
                        NutritionRecordStatus::Approved->value,
                        NutritionRecordStatus::Active->value,
                    ])
                    ->when($beneficiaryPeriodId !== null, fn ($query) => $query->where('beneficiary_period_id', $beneficiaryPeriodId));
            })
            ->latest('id')
            ->first();
    }

    /** @return Collection<int, DailyBeneficiaryConfirmation> */
    private function confirmedActualData(int $unitId, string $serviceDate, ?int $beneficiaryPeriodId): Collection
    {
        return DailyBeneficiaryConfirmation::query()
            ->with('items')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('service_date', $serviceDate)
            ->when($beneficiaryPeriodId !== null, fn ($query) => $query->where('beneficiary_period_id', $beneficiaryPeriodId))
            ->whereIn('status', ['confirmed', 'changed'])
            ->orderBy('destination_name_snapshot')
            ->get();
    }

    /** @param Collection<int, DailyBeneficiaryConfirmation> $confirmations */
    private function actualTotals(Collection $confirmations): array
    {
        $registered = 0;
        $confirmed = 0;
        $small = 0;
        $large = 0;

        foreach ($confirmations as $confirmation) {
            foreach ($confirmation->items as $item) {
                $actual = (int) $item->actual_count;
                $registered += (int) $item->master_count;
                $confirmed += $actual;

                if ($item->portion_category === 'large') {
                    $large += $actual;
                } else {
                    $small += $actual;
                }
            }
        }

        return [
            'registered' => $registered,
            'confirmed' => $confirmed,
            'small' => $small,
            'large' => $large,
            'total' => $small + $large,
        ];
    }

    /** @param Collection<int, DailyBeneficiaryConfirmation> $confirmations */
    private function replaceDestinations(FieldDistributionPlan $plan, Collection $confirmations): void
    {
        $plan->destinations()->delete();

        foreach ($confirmations->values() as $index => $confirmation) {
            $groups = $confirmation->items;
            $small = (int) $groups->where('portion_category', 'small')->sum('actual_count');
            $large = (int) $groups->where('portion_category', 'large')->sum('actual_count');
            $registered = (int) $groups->sum('master_count');
            $confirmed = (int) $groups->sum('actual_count');
            $changed = $groups->contains(fn ($item): bool => (int) $item->master_count !== (int) $item->actual_count);
            $reasons = $groups->pluck('change_reason')->filter()->unique()->implode('; ');

            $destination = $plan->destinations()->create([
                'daily_beneficiary_confirmation_id' => $confirmation->getKey(),
                'destination_type' => $confirmation->destination_type,
                'destination_id' => $confirmation->destination_id,
                'destination_code_snapshot' => $confirmation->destination_code_snapshot,
                'destination_name_snapshot' => $confirmation->destination_name_snapshot,
                'address_snapshot' => $confirmation->address_snapshot,
                'contact_name_snapshot' => $confirmation->contact_name_snapshot,
                'contact_phone_snapshot' => $confirmation->contact_phone_snapshot,
                'route_name' => $confirmation->destination_name_snapshot,
                'sequence_order' => $index + 1,
                'registered_beneficiaries' => $registered,
                'confirmed_beneficiaries' => $confirmed,
                'small_portions' => $small,
                'large_portions' => $large,
                'total_portions' => $small + $large,
                'confirmation_status' => $changed ? 'changed' : 'confirmed',
                'confirmed_at' => $confirmation->confirmed_at ?: now(),
                'confirmed_by_name' => $confirmation->confirmed_by_name,
                'change_reason' => $changed ? ($reasons ?: 'Jumlah aktual berubah dari master periode.') : null,
                'special_notes' => $confirmation->notes,
            ]);

            foreach ($groups as $group) {
                $destination->recipientGroups()->create([
                    'beneficiary_category_id' => $group->beneficiary_category_id,
                    'beneficiary_category_code_snapshot' => $group->beneficiary_category_code_snapshot,
                    'beneficiary_category_name_snapshot' => $group->beneficiary_category_name_snapshot,
                    'menu_audience' => $group->menu_audience ?: 'student',
                    'portion_size' => $group->portion_category === 'large' ? 'large' : 'small',
                    'registered_beneficiaries' => (int) $group->master_count,
                    'confirmed_beneficiaries' => (int) $group->actual_count,
                    'notes' => $group->change_reason,
                ]);
            }
        }

        $plan->refresh()->recalculateTotals();
    }
}
