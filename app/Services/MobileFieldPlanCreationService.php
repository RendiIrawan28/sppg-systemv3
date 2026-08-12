<?php

namespace App\Services;

use App\Enums\NutritionRecordStatus;
use App\Models\FieldDistributionPlan;
use App\Models\MenuCycleDay;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileFieldPlanCreationService
{
    public function __construct(
        private readonly FieldPlanActualConfirmationService $confirmationService,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function options(int $unitId): array
    {
        return MenuCycleDay::query()
            ->with(['cycle', 'menu'])
            ->whereNotNull('menu_id')
            ->whereHas('cycle', fn ($query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereIn('status', [
                    NutritionRecordStatus::Approved->value,
                    NutritionRecordStatus::Active->value,
                ]))
            ->whereDate('delivery_date', '>=', now()->subDays(7))
            ->orderBy('delivery_date')
            ->limit(120)
            ->get()
            ->map(function (MenuCycleDay $day) use ($unitId): array {
                $distributionDate = $day->delivery_date?->toDateString()
                    ?: $day->service_date?->toDateString();
                $hasPlan = FieldDistributionPlan::query()
                    ->where('menu_cycle_day_id', $day->getKey())
                    ->exists();
                $unavailableReason = null;
                if (! $hasPlan) {
                    try {
                        $this->confirmationService->readyPeriod(
                            $unitId,
                            $day->service_date?->toDateString() ?: $distributionDate,
                        );
                    } catch (DomainException $exception) {
                        $unavailableReason = $exception->getMessage();
                    }
                }

                return [
                    'id' => $day->getKey(),
                    'cycle_code' => $day->cycle?->code,
                    'cycle_name' => $day->cycle?->name,
                    'day_number' => (int) $day->day_number,
                    'label_code' => $day->label_code,
                    'menu_name' => $day->menu?->name ?: 'Menu Siklus',
                    'distribution_date' => $distributionDate,
                    'service_date' => $day->service_date?->toDateString() ?: $distributionDate,
                    'production_date' => $day->production_date?->toDateString() ?: $distributionDate,
                    'is_rapel' => (bool) $day->is_rapel,
                    'has_plan' => $hasPlan,
                    'is_available' => ! $hasPlan && $unavailableReason === null,
                    'unavailable_reason' => $hasPlan
                        ? 'Rencana distribusi untuk menu dan tanggal ini sudah tersedia.'
                        : $unavailableReason,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function create(int $unitId, User $actor, array $data): FieldDistributionPlan
    {
        $cycleDay = MenuCycleDay::query()
            ->with(['cycle', 'menu'])
            ->whereKey($data['menu_cycle_day_id'])
            ->whereNotNull('menu_id')
            ->whereHas('cycle', fn ($query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereIn('status', [
                    NutritionRecordStatus::Approved->value,
                    NutritionRecordStatus::Active->value,
                ]))
            ->firstOrFail();

        if (FieldDistributionPlan::query()->where('menu_cycle_day_id', $cycleDay->getKey())->exists()) {
            throw ValidationException::withMessages([
                'menu_cycle_day_id' => 'Rencana distribusi untuk menu dan tanggal ini sudah tersedia.',
            ]);
        }

        $distributionDate = $cycleDay->delivery_date?->toDateString()
            ?: $cycleDay->service_date?->toDateString();
        if (! $distributionDate) {
            throw ValidationException::withMessages([
                'menu_cycle_day_id' => 'Tanggal distribusi pada menu siklus belum tersedia.',
            ]);
        }

        try {
            $period = $this->confirmationService->readyPeriod(
                $unitId,
                $cycleDay->service_date?->toDateString() ?: $distributionDate,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['menu_cycle_day_id' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($unitId, $actor, $data, $cycleDay, $distributionDate, $period): FieldDistributionPlan {
            $plan = FieldDistributionPlan::query()->create([
                'sppg_unit_id' => $unitId,
                'beneficiary_period_id' => $period->getKey(),
                'menu_cycle_day_id' => $cycleDay->getKey(),
                'distribution_date' => $distributionDate,
                'service_date' => $cycleDay->service_date?->toDateString() ?: $distributionDate,
                'production_date' => $cycleDay->production_date?->toDateString()
                    ?: $cycleDay->delivery_date?->toDateString()
                    ?: $distributionDate,
                'is_rapel' => (bool) $cycleDay->is_rapel,
                'menu_id' => $cycleDay->menu_id,
                'menu_name_snapshot' => $cycleDay->menu?->name ?: 'Menu Siklus',
                'meal_type' => 'lunch',
                'shift' => 'morning',
                'confirmation_deadline_at' => $data['confirmation_deadline_at'] ?? now()->addDay(),
                'general_notes' => filled($data['general_notes'] ?? null)
                    ? trim((string) $data['general_notes'])
                    : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'source_system' => 'mobile',
            ]);

            $this->confirmationService->synchronize($plan, $actor);
            $cycleDay->forceFill(['field_distribution_plan_id' => $plan->getKey()])->save();

            return $plan->refresh()->load('destinations.recipientGroups');
        });
    }
}
