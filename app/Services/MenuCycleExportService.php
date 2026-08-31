<?php

namespace App\Services;

use App\Models\MenuCycle;
use App\Models\MenuCycleDayVariant;
use Illuminate\Support\Collection;

class MenuCycleExportService
{
    /**
     * @return array{
     *     hasDifferent3BMenu: bool,
     *     schoolMenus: Collection<int, mixed>,
     *     threeBMenus: Collection<int, mixed>
     * }
     */
    public function prepare(MenuCycle $cycle): array
    {
        $cycle->loadMissing([
            'days.menu.items',
            'days.variants.menu.items',
        ]);

        $threeBMenus = $cycle->days->mapWithKeys(function ($day): array {
            return [
                (int) $day->getKey() => $day->effectiveMenuForAudience(
                    MenuAudienceMenuResolver::POSYANDU_3B,
                ),
            ];
        });

        return [
            'hasDifferent3BMenu' => $cycle->days->contains(
                fn ($day): bool => $day->variants->contains(
                    'audience_type',
                    MenuCycleDayVariant::AUDIENCE_POSYANDU_3B,
                ),
            ),
            'schoolMenus' => $cycle->days->mapWithKeys(
                fn ($day): array => [(int) $day->getKey() => $day->menu],
            ),
            'threeBMenus' => $threeBMenus,
        ];
    }
}
