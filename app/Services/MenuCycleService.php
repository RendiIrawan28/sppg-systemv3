<?php

namespace App\Services;

use App\Models\MenuCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuCycleService
{
    public function __construct(
        private readonly MenuServiceCalendarService $calendar,
        private readonly MenuNutritionWarningService $warnings,
    ) {}

    public function rebuildDays(MenuCycle $cycle): void
    {
        if (! $cycle->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Siklus yang sudah diajukan tidak dapat disusun ulang.',
            ]);
        }

        DB::transaction(function () use ($cycle): void {
            $locked = MenuCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());
            $minimum = (int) config('nutrition_menu.minimum_cycle_length_days', 1);
            $maximum = (int) config('nutrition_menu.maximum_cycle_length_days', 60);
            $count = max($minimum, min($maximum, (int) $locked->cycle_length_days));

            $days = $this->calendar->build(
                unitId: (int) $locked->sppg_unit_id,
                startDate: $locked->start_date,
                count: $count,
            );
            $existing = $locked->days()->get()->keyBy('day_number');

            foreach ($days as $day) {
                $old = $existing->get($day['day_number']);
                $locked->days()->updateOrCreate(
                    ['day_number' => $day['day_number']],
                    [
                        ...$day,
                        'menu_id' => $this->calendar->isHoliday((int) $locked->sppg_unit_id, $day['service_date']) ? null : $old?->menu_id,
                        'source_menu_id' => $this->calendar->isHoliday((int) $locked->sppg_unit_id, $day['service_date']) ? null : $old?->source_menu_id,
                        'snapshot_version' => $old?->snapshot_version ?? 0,
                        'snapshot_created_at' => $old?->snapshot_created_at,
                        'field_distribution_plan_id' => $old?->field_distribution_plan_id,
                        'notes' => $old?->notes,
                    ],
                );
            }

            $locked->days()->where('day_number', '>', $count)->delete();
            $locked->update([
                'cycle_length_days' => $count,
                'end_date' => collect($days)->last()['service_date'] ?? $locked->start_date,
                'meal_type' => 'lunch',
            ]);
        });

        $cycle->refresh();
    }

    /** @return array{blocking: array<int,string>, warnings: array<int,string>} */
    public function readinessReport(MenuCycle $cycle): array
    {
        $cycle->loadMissing('days.menu.items.recipeIngredients', 'days.menu.categoryTargets.category');
        $blocking = [];
        $warnings = [];

        $expectedDays = max(
            (int) config('nutrition_menu.minimum_cycle_length_days', 1),
            min(
                (int) config('nutrition_menu.maximum_cycle_length_days', 60),
                (int) $cycle->cycle_length_days,
            ),
        );

        if (! $cycle->beneficiary_period_id) {
            $blocking[] = 'Siklus belum terhubung dengan data penerima dari Asisten Lapangan.';
        }

        if ($cycle->days->count() !== $expectedDays) {
            $blocking[] = "Siklus wajib memiliki tepat {$expectedDays} hari pelayanan.";
        }

        $checkedMenus = [];

        foreach ($cycle->days as $day) {
            if ($day->isHoliday()) {
                continue;
            }

            if (! $day->menu) {
                $blocking[] = "Hari ke-{$day->day_number} belum memiliki menu.";

                continue;
            }

            if ((int) $day->menu->sppg_unit_id !== (int) $cycle->sppg_unit_id) {
                $blocking[] = "Menu hari ke-{$day->day_number} berasal dari Unit SPPG lain.";

                continue;
            }

            $menuId = (int) $day->menu->getKey();

            if (! isset($checkedMenus[$menuId])) {
                $checkedMenus[$menuId] = [
                    'blocking' => $this->warnings->blockingIssues($day->menu),
                    'warnings' => [],
                ];

                if ($checkedMenus[$menuId]['blocking'] === []) {
                    app(MenuNutritionCalculator::class)->refresh($day->menu);
                    $checkedMenus[$menuId]['warnings'] = $this->warnings->nutritionWarnings($day->menu->refresh());
                }
            }

            $blocking = [...$blocking, ...array_map(
                fn (string $issue): string => "Hari ke-{$day->day_number}: {$issue}",
                $checkedMenus[$menuId]['blocking'],
            )];
            $warnings = [...$warnings, ...array_map(
                fn (string $warning): string => "Hari ke-{$day->day_number}: {$warning}",
                $checkedMenus[$menuId]['warnings'],
            )];
        }

        return [
            'blocking' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function activate(MenuCycle $cycle): void
    {
        app(MenuCycleWorkflowService::class)->activate($cycle);
    }

    public function applyToDistributionPlans(MenuCycle $cycle): int
    {
        throw ValidationException::withMessages([
            'field_planning' => 'Alur lama sinkronisasi dari Siklus Menu ke Rencana Distribusi sudah dinonaktifkan. Asisten Lapangan membuat Rencana Distribusi langsung dari siklus aktif dan periode penerima aktif.',
        ]);
    }
}
