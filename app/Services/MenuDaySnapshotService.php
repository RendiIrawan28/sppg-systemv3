<?php

namespace App\Services;

use App\Enums\MenuStatus;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuDaySnapshotService
{
    public function __construct(
        private readonly MenuCloneService $cloneService,
    ) {}

    /**
     * Membuat salinan independen untuk setiap hari saat siklus diajukan.
     * Menu master tetap utuh dan dapat dipakai pada siklus lain.
     */
    public function snapshotCycle(MenuCycle $cycle, User $user): void
    {
        DB::transaction(function () use ($cycle, $user): void {
            $locked = MenuCycle::query()
                ->with(['days.menu', 'days.variants.menu'])
                ->lockForUpdate()
                ->findOrFail($cycle->getKey());

            foreach ($locked->days as $day) {
                if ($day->isHoliday()) {
                    continue;
                }

                $variantSources = $day->variants
                    ->mapWithKeys(fn ($variant): array => [
                        $variant->getKey() => $variant->menu,
                    ]);

                $this->snapshotDay($locked, $day, $user);

                foreach ($variantSources as $variantId => $source) {
                    if (! $source) {
                        continue;
                    }

                    $snapshot = $this->cloneService->cloneForCycleSnapshot(
                        source: $source,
                        day: $day,
                        creator: $user,
                        version: max(1, (int) $day->snapshot_version),
                        plannedPortions: $locked->bufferedTotalPortions(),
                    );
                    $day->variants()->whereKey($variantId)->update([
                        'menu_id' => $snapshot->getKey(),
                    ]);

                    if ($source->is_cycle_snapshot && $source->isEditable()) {
                        $source->updateQuietly(['status' => MenuStatus::Archived]);
                    }
                }
            }
        });
    }

    private function snapshotDay(MenuCycle $cycle, MenuCycleDay $day, User $user): void
    {
        $source = $day->menu;

        if (! $source) {
            throw ValidationException::withMessages([
                'menu' => "Hari ke-{$day->day_number} belum memiliki menu.",
            ]);
        }

        $sourceMenuId = (int) ($day->source_menu_id
            ?: $source->source_menu_id
            ?: $source->getKey());
        $version = max(1, (int) $day->snapshot_version + 1);

        $snapshot = $this->cloneService->cloneForCycleSnapshot(
            source: $source,
            day: $day,
            creator: $user,
            version: $version,
            plannedPortions: $cycle->bufferedTotalPortions(),
        );

        if ($source->is_cycle_snapshot && $source->isEditable()) {
            $source->updateQuietly(['status' => MenuStatus::Archived]);
        }

        $day->update([
            'source_menu_id' => $sourceMenuId,
            'menu_id' => $snapshot->getKey(),
            'snapshot_version' => $version,
            'snapshot_created_at' => now(),
        ]);
    }
}
