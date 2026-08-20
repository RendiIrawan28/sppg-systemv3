<?php

namespace App\Services;

use App\Models\FieldDistributionPlan;
use App\Models\MenuCycleDay;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
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
        $start = now()->startOfDay();
        $plans = FieldDistributionPlan::query()->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', '>=', $start)->pluck('id', 'distribution_date');

        return collect(range(0, 365))->map(function (int $offset) use ($unitId, $start, $plans): array {
                $distributionDate = $start->copy()->addDays($offset)->toDateString();
                $hasPlan = $plans->has($distributionDate);
                $unavailableReason = null;
                if (! $hasPlan) {
                    try {
                        $this->confirmationService->readyPeriod($unitId, $distributionDate);
                    } catch (DomainException $exception) {
                        $unavailableReason = $exception->getMessage();
                    }
                }

                return [
                    'id' => $start->copy()->addDays($offset)->timestamp,
                    'cycle_code' => null, 'cycle_name' => null, 'day_number' => $offset + 1, 'label_code' => null,
                    'menu_name' => 'Tidak terikat menu',
                    'distribution_date' => $distributionDate,
                    'service_date' => $distributionDate, 'production_date' => $distributionDate,
                    'is_rapel' => false,
                    'has_plan' => $hasPlan,
                    'is_available' => ! $hasPlan && $unavailableReason === null,
                    'unavailable_reason' => $hasPlan
                        ? 'Rencana distribusi untuk tanggal ini sudah tersedia.'
                        : $unavailableReason,
                ];
            })->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function create(int $unitId, User $actor, array $data): FieldDistributionPlan
    {
        $distributionDate = $data['distribution_date'] ?? null;
        if (! $distributionDate && filled($data['menu_cycle_day_id'] ?? null)) {
            $legacyDay = MenuCycleDay::query()
                ->whereKey($data['menu_cycle_day_id'])
                ->whereHas('cycle', fn ($query) => $query->where('sppg_unit_id', $unitId))
                ->first();
            $distributionDate = $legacyDay?->delivery_date?->toDateString()
                ?: $legacyDay?->service_date?->toDateString();
        }
        if (! $distributionDate) {
            throw ValidationException::withMessages(['distribution_date' => 'Tanggal distribusi wajib diisi.']);
        }
        if (now()->startOfDay()->gt(Carbon::parse($distributionDate)->startOfDay())) {
            throw ValidationException::withMessages(['distribution_date' => 'Tanggal distribusi tidak boleh sebelum hari ini.']);
        }
        if (FieldDistributionPlan::query()->where('sppg_unit_id', $unitId)->whereDate('distribution_date', $distributionDate)->exists()) {
            throw ValidationException::withMessages([
                'distribution_date' => 'Rencana distribusi untuk tanggal ini sudah tersedia.',
            ]);
        }
        try {
            $period = $this->confirmationService->readyPeriod($unitId, $distributionDate);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['distribution_date' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($unitId, $actor, $data, $distributionDate, $period): FieldDistributionPlan {
            $plan = FieldDistributionPlan::query()->create([
                'sppg_unit_id' => $unitId,
                'beneficiary_period_id' => $period->getKey(),
                'menu_cycle_day_id' => null,
                'distribution_date' => $distributionDate,
                'service_date' => $distributionDate, 'production_date' => $distributionDate,
                'is_rapel' => false, 'menu_id' => null, 'menu_name_snapshot' => null,
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

            return $plan->refresh()->load('destinations.recipientGroups');
        });
    }
}
