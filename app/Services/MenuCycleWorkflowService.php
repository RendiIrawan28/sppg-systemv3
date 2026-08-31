<?php

namespace App\Services;

use App\Enums\MenuStatus;
use App\Enums\NutritionRecordStatus;
use App\Models\MenuCycle;
use App\Models\NutritionWorkflowHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuCycleWorkflowService
{
    public function submit(MenuCycle $cycle): void
    {
        abort_unless(auth()->user()?->can('menus.submit') === true, 403);

        $report = app(MenuCycleService::class)->readinessReport($cycle);

        if ($report['blocking'] !== []) {
            throw ValidationException::withMessages([
                'readiness' => implode("\n", $report['blocking']),
            ]);
        }

        DB::transaction(function () use ($cycle): void {
            $locked = MenuCycle::query()
                ->with('days.menu', 'days.variants.menu')
                ->lockForUpdate()
                ->findOrFail($cycle->getKey());

            if (! in_array($locked->status, [NutritionRecordStatus::Draft, NutritionRecordStatus::RevisionRequired], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Siklus tidak dapat diajukan pada status saat ini.',
                ]);
            }

            $previous = $locked->status;

            // Setiap hari mendapat clone tersendiri. Menu yang sama pada dua hari
            // tidak lagi saling memengaruhi setelah pengajuan.
            app(MenuDaySnapshotService::class)->snapshotCycle($locked, auth()->user());
            $locked->load(
                'days.menu.nutritionSummaries.component',
                'days.menu.nutritionSummaries.category',
                'days.variants.menu.nutritionSummaries.component',
                'days.variants.menu.nutritionSummaries.category',
            );

            $snapshotWarnings = [];
            foreach ($locked->days as $day) {
                if (! $day->menu) {
                    continue;
                }

                $snapshotWarnings = [
                    ...$snapshotWarnings,
                    ...array_map(
                        fn (string $warning): string => "Hari ke-{$day->day_number}: {$warning}",
                        app(MenuNutritionWarningService::class)->nutritionWarnings($day->menu),
                    ),
                ];
                foreach ($day->variants as $variant) {
                    if (! $variant->menu) {
                        continue;
                    }
                    $snapshotWarnings = [
                        ...$snapshotWarnings,
                        ...array_map(
                            fn (string $warning): string => "Hari ke-{$day->day_number} (Menu 3B): {$warning}",
                            app(MenuNutritionWarningService::class)->nutritionWarnings($variant->menu),
                        ),
                    ];
                }
            }
            $snapshotWarnings = array_values(array_unique($snapshotWarnings));

            $locked->update([
                'status' => NutritionRecordStatus::Submitted,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'revision_notes' => null,
                'nutrition_warning_count' => count($snapshotWarnings),
            ]);

            foreach ($locked->days as $day) {
                $day->menu?->update([
                    'status' => MenuStatus::PendingReview,
                    'submitted_by' => auth()->id(),
                    'submitted_at' => now(),
                    'review_notes' => null,
                ]);
                foreach ($day->variants as $variant) {
                    $variant->menu?->update([
                        'status' => MenuStatus::PendingReview,
                        'submitted_by' => auth()->id(),
                        'submitted_at' => now(),
                        'review_notes' => null,
                    ]);
                }
            }

            $this->history($locked, 'submitted', $previous->value, NutritionRecordStatus::Submitted->value, [
                'nutrition_warnings' => $snapshotWarnings,
                'snapshot_versions' => $locked->days->mapWithKeys(
                    fn ($day): array => [(string) $day->day_number => (int) $day->snapshot_version],
                )->all(),
            ]);
        });

        $cycle->refresh();
    }

    public function approve(MenuCycle $cycle): void
    {
        abort_unless(auth()->user()?->can('menus.approve') === true, 403);

        DB::transaction(function () use ($cycle): void {
            $locked = MenuCycle::query()
                ->with('days.menu', 'days.variants.menu')
                ->lockForUpdate()
                ->findOrFail($cycle->getKey());

            if ($locked->status !== NutritionRecordStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya siklus yang menunggu persetujuan yang dapat disetujui.',
                ]);
            }

            if ((int) $locked->submitted_by === (int) auth()->id()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengaju tidak boleh menyetujui siklusnya sendiri.',
                ]);
            }

            $locked->update([
                'status' => NutritionRecordStatus::Approved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'locked_at' => now(),
                'revision_notes' => null,
            ]);

            foreach ($locked->days as $day) {
                $day->menu?->update([
                    'status' => MenuStatus::Approved,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'review_notes' => null,
                ]);
                foreach ($day->variants as $variant) {
                    $variant->menu?->update([
                        'status' => MenuStatus::Approved,
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                        'review_notes' => null,
                    ]);
                }
            }

            $this->history($locked, 'approved', NutritionRecordStatus::Submitted->value, NutritionRecordStatus::Approved->value);
        });

        $cycle->refresh();
    }

    public function requestRevision(MenuCycle $cycle, string $notes): void
    {
        abort_unless(auth()->user()?->can('menus.approve') === true, 403);

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Catatan revisi wajib diisi.']);
        }

        DB::transaction(function () use ($cycle, $notes): void {
            $locked = MenuCycle::query()
                ->with('days.menu', 'days.variants.menu')
                ->lockForUpdate()
                ->findOrFail($cycle->getKey());

            if ($locked->status !== NutritionRecordStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya siklus yang menunggu persetujuan yang dapat dikembalikan.',
                ]);
            }

            $locked->update([
                'status' => NutritionRecordStatus::RevisionRequired,
                'revision_notes' => $notes,
                'revision_number' => (int) $locked->revision_number + 1,
                'locked_at' => null,
            ]);

            foreach ($locked->days as $day) {
                $day->menu?->update([
                    'status' => MenuStatus::RevisionRequired,
                    'review_notes' => $notes,
                ]);
                foreach ($day->variants as $variant) {
                    $variant->menu?->update([
                        'status' => MenuStatus::RevisionRequired,
                        'review_notes' => $notes,
                    ]);
                }
            }

            $this->history($locked, 'revision_requested', NutritionRecordStatus::Submitted->value, NutritionRecordStatus::RevisionRequired->value, ['notes' => $notes]);
        });

        $cycle->refresh();
    }

    public function activate(MenuCycle $cycle): void
    {
        abort_unless(auth()->user()?->can('menus.activate') === true, 403);

        DB::transaction(function () use ($cycle): void {
            $locked = MenuCycle::query()
                ->with('days.menu', 'days.variants.menu')
                ->lockForUpdate()
                ->findOrFail($cycle->getKey());

            if ($locked->status !== NutritionRecordStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya siklus yang telah disetujui yang dapat diaktifkan.',
                ]);
            }

            MenuCycle::query()
                ->where('sppg_unit_id', $locked->sppg_unit_id)
                ->whereKeyNot($locked->getKey())
                ->where('status', NutritionRecordStatus::Active->value)
                ->update(['status' => NutritionRecordStatus::Archived->value]);

            $locked->update([
                'status' => NutritionRecordStatus::Active,
                'activated_at' => now(),
            ]);

            foreach ($locked->days as $day) {
                $day->menu?->update(['status' => MenuStatus::InUse]);
                foreach ($day->variants as $variant) {
                    $variant->menu?->update(['status' => MenuStatus::InUse]);
                }
            }

            $this->history($locked, 'activated', NutritionRecordStatus::Approved->value, NutritionRecordStatus::Active->value);
        });

        $cycle->refresh();
    }

    private function history(MenuCycle $cycle, string $action, string $from, string $to, array $snapshot = []): void
    {
        NutritionWorkflowHistory::query()->create([
            'sppg_unit_id' => $cycle->sppg_unit_id,
            'subject_type' => $cycle->getMorphClass(),
            'subject_id' => $cycle->getKey(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $snapshot['notes'] ?? null,
            'snapshot' => $snapshot,
            'actor_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
