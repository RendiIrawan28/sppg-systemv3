<?php

namespace App\Services;

use App\Models\ServiceHoliday;
use Carbon\CarbonImmutable;

class MenuServiceCalendarService
{
    /** @return array<int, array<string, mixed>> */
    public function build(int $unitId, string|\DateTimeInterface $startDate, ?int $count = null): array
    {
        $count ??= (int) config('nutrition_menu.default_cycle_length_days', 5);
        $count = max(1, $count);

        $cursor = CarbonImmutable::parse($startDate)->startOfDay();
        $result = [];

        while (count($result) < $count) {
            if (! $cursor->isSunday() && ! $this->isHoliday($unitId, $cursor)) {
                $isSaturday = $cursor->isSaturday();
                $operationDate = $isSaturday
                    ? $this->previousOperationalDate($unitId, $cursor)
                    : $cursor;

                $result[] = [
                    'day_number' => count($result) + 1,
                    'service_date' => $cursor->toDateString(),
                    'production_date' => $operationDate->toDateString(),
                    'delivery_date' => $operationDate->toDateString(),
                    'is_rapel' => $isSaturday,
                    'label_code' => sprintf('MENU-%s-%02d', $cursor->format('Ymd'), count($result) + 1),
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $result;
    }

    public function isHoliday(int $unitId, CarbonImmutable $date): bool
    {
        return ServiceHoliday::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('holiday_date', $date->toDateString())
            ->where('is_active', true)
            ->exists();
    }

    private function previousOperationalDate(int $unitId, CarbonImmutable $date): CarbonImmutable
    {
        $candidate = $date->subDay();

        while ($candidate->isSunday() || $this->isHoliday($unitId, $candidate)) {
            $candidate = $candidate->subDay();
        }

        return $candidate;
    }
}
