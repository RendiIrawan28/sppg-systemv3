<?php

namespace App\Services;

use App\Enums\FieldDistributionPlanStatus;
use App\Enums\NutritionRecordStatus;
use App\Models\FieldDistributionPlan;
use App\Models\MenuCycleDay;
use App\Models\ServiceHoliday;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use stdClass;

class MenuServiceCalendarService
{
    /** @return array<int, array<string, mixed>> */
    public function build(int $unitId, string|DateTimeInterface $startDate, ?int $count = null): array
    {
        $count ??= (int) config('nutrition_menu.default_cycle_length_days', 5);
        $count = max(1, $count);

        $cursor = CarbonImmutable::parse($startDate)->startOfDay();
        $result = [];

        while (count($result) < $count) {
            $result[] = [
                'day_number' => count($result) + 1,
                'service_date' => $cursor->toDateString(),
                'production_date' => $cursor->toDateString(),
                'delivery_date' => $cursor->toDateString(),
                'is_rapel' => false,
                'label_code' => sprintf('MENU-%s-%02d', $cursor->format('Ymd'), count($result) + 1),
            ];

            $cursor = $cursor->addDay();
        }

        return $result;
    }

    public function holidayFor(int $unitId, string|DateTimeInterface $date): ServiceHoliday|stdClass|null
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $holiday = ServiceHoliday::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('holiday_date', $date->toDateString())
            ->where('is_active', true)
            ->first();

        if ($holiday) {
            return $holiday;
        }

        if (! $date->isWeekend()) {
            return null;
        }

        return (object) [
            'id' => null,
            'holiday_date' => $date,
            'name' => 'Libur Pelayanan ('.$date->translatedFormat('l').')',
            'holiday_type' => 'weekend',
            'is_active' => true,
            'is_weekend' => true,
        ];
    }

    public function isHoliday(int $unitId, string|DateTimeInterface $date): bool
    {
        return $this->holidayFor($unitId, $date) !== null;
    }

    public function assertOperationalDate(int $unitId, string|DateTimeInterface $date, string $activity = 'Kegiatan'): void
    {
        $holiday = $this->holidayFor($unitId, $date);
        if ($holiday) {
            $formatted = CarbonImmutable::parse($date)->format('d-m-Y');
            throw new DomainException("Tanggal {$formatted} merupakan hari libur pelayanan: {$holiday->name}. {$activity} tidak dapat dilakukan.");
        }
    }

    public function hasServiceOnNextDay(int $unitId, string|DateTimeInterface $workDate): bool
    {
        $serviceDate = CarbonImmutable::parse($workDate)->addDay()->startOfDay();
        if ($this->isHoliday($unitId, $serviceDate)) {
            return false;
        }

        if (FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $serviceDate->toDateString())
            ->where('status', FieldDistributionPlanStatus::Activated->value)
            ->exists()) {
            return true;
        }

        return MenuCycleDay::query()
            ->whereDate('service_date', $serviceDate->toDateString())
            ->whereNotNull('menu_id')
            ->whereHas('cycle', fn ($query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereIn('status', [
                    NutritionRecordStatus::Approved->value,
                    NutritionRecordStatus::Active->value,
                ]))
            ->exists();
    }
}
