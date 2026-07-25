<?php

namespace App\Services;

use App\Enums\MenuAudience;
use App\Enums\MenuComponentType;
use App\Enums\MenuPortionProfile;
use App\Models\Menu;
use BackedEnum;

class MenuNutritionWarningService
{
    /** @return array<int, string> */
    public function blockingIssues(Menu $menu): array
    {
        $menu->loadMissing([
            'items.recipeIngredients',
            'categoryTargets.category',
        ]);

        $issues = [];
        $requiredTypes = config('nutrition_menu.required_components', []);
        $conditionalGroups = config('nutrition_menu.at_least_one_component_groups', []);
        $audiences = $menu->categoryTargets
            ->map(fn ($target) => $target->category)
            ->filter()
            ->map(fn ($category) => app(MenuPortionProfileResolver::class)->audienceForCategory($category)->value)
            ->unique()
            ->values();

        if ($audiences->isEmpty()) {
            $issues[] = 'Menu belum memiliki target kategori penerima.';
        }

        foreach ($audiences as $audience) {
            foreach ($requiredTypes as $type) {
                if (! $this->hasCompleteComponent($menu, (string) $type, (string) $audience)) {
                    $label = MenuComponentType::tryFrom((string) $type)?->label() ?? $type;
                    $audienceLabel = MenuAudience::tryFrom((string) $audience)?->label() ?? $audience;
                    $issues[] = "Komponen {$label} untuk {$audienceLabel} belum lengkap.";
                }
            }

            foreach ($conditionalGroups as $types) {
                $types = array_values(array_filter((array) $types));
                $complete = collect($types)->contains(
                    fn (string $type): bool => $this->hasCompleteComponent($menu, $type, (string) $audience),
                );

                if (! $complete) {
                    $labels = collect($types)
                        ->map(fn (string $type): string => MenuComponentType::tryFrom($type)?->label() ?? $type)
                        ->implode(' atau ');
                    $audienceLabel = MenuAudience::tryFrom((string) $audience)?->label() ?? $audience;
                    $issues[] = "Minimal salah satu komponen {$labels} untuk {$audienceLabel} wajib tersedia.";
                }
            }
        }

        foreach ($menu->items as $item) {
            if ($item->recipeIngredients->isEmpty()) {
                $issues[] = "Hidangan {$item->name} belum memiliki bahan resep.";

                continue;
            }

            foreach ($item->recipeIngredients as $recipe) {
                foreach (MenuPortionProfile::cases() as $profile) {
                    if ($recipe->gramsFor($profile) <= 0) {
                        $issues[] = "Berat bahan pada {$item->name} untuk {$profile->label()} belum diisi.";
                    }
                }
            }
        }

        return array_values(array_unique($issues));
    }

    /** @return array<int, string> */
    public function nutritionWarnings(Menu $menu): array
    {
        $menu->loadMissing([
            'nutritionSummaries.component',
            'nutritionSummaries.category',
        ]);

        $validatedCodes = array_map('strtolower', config('nutrition_menu.validated_nutrients', []));
        $minimum = (float) config('nutrition_menu.tolerance.minimum_percent', 90);
        $maximum = (float) config('nutrition_menu.tolerance.maximum_percent', 110);
        $warnings = [];

        foreach ($menu->nutritionSummaries as $summary) {
            $code = strtolower((string) $summary->component?->code);

            if (! in_array($code, $validatedCodes, true)) {
                continue;
            }

            if ($summary->achievement_percent === null) {
                $warnings[] = sprintf(
                    'Standar %s untuk %s belum tersedia.',
                    $summary->component?->name ?? $code,
                    $summary->category?->name ?? 'kategori penerima',
                );

                continue;
            }

            $percent = (float) $summary->achievement_percent;

            if ($percent < $minimum || $percent > $maximum) {
                $status = $percent < $minimum ? 'kurang' : 'berlebih';
                $warnings[] = sprintf(
                    '%s untuk %s %s: kontribusi menu %.2f%% dari kebutuhan harian (rentang sekali makan %.0f–%.0f%%).',
                    $summary->component?->name ?? $code,
                    $summary->category?->name ?? 'kategori penerima',
                    $status,
                    $percent,
                    $minimum,
                    $maximum,
                );
            }
        }

        return array_values(array_unique($warnings));
    }

    private function hasCompleteComponent(Menu $menu, string $type, string $audience): bool
    {
        return $menu->items->contains(function ($item) use ($audience, $type): bool {
            $itemAudience = $this->enumValue($item->menu_audience ?? 'all', 'all');

            return $this->enumValue($item->item_type) === $type
                && in_array($itemAudience, ['all', $audience], true)
                && $item->recipeIngredients->isNotEmpty();
        });
    }

    private function enumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }
}
