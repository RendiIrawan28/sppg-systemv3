<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\MenuCycleDay;
use App\Models\MenuCycleDayVariant;

class MenuAudienceMenuResolver
{
    public const SCHOOL = 'school';

    public const POSYANDU_3B = MenuCycleDayVariant::AUDIENCE_POSYANDU_3B;

    public function effectiveMenu(MenuCycleDay $day, string $audienceType): ?Menu
    {
        if ($audienceType !== self::POSYANDU_3B) {
            return $day->menu;
        }

        $day->loadMissing('variants.menu');

        return $day->variants
            ->firstWhere('audience_type', self::POSYANDU_3B)
            ?->menu ?? $day->menu;
    }

    /** @param array<string, mixed> $allocation */
    public function allocationAudience(array $allocation): string
    {
        $audience = strtolower(trim((string) ($allocation['menu_audience'] ?? '')));

        return in_array($audience, ['toddler', 'maternal'], true)
            ? self::POSYANDU_3B
            : self::SCHOOL;
    }
}
