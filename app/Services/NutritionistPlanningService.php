<?php

namespace App\Services;

use App\Enums\NutritionRecordStatus;
use App\Models\BeneficiaryPeriod;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NutritionistPlanningService
{
    /** @return array<string, mixed> */
    public function summary(BeneficiaryPeriod $period, float $bufferPercent): array
    {
        $bufferPercent = $this->normalizeBuffer($bufferPercent);

        $rows = $period->members()
            ->active()
            ->selectRaw("COALESCE(beneficiary_category_id, 0) AS category_id")
            ->selectRaw("COALESCE(NULLIF(beneficiary_category_code_snapshot, ''), 'lainnya') AS category_code")
            ->selectRaw("COALESCE(NULLIF(beneficiary_category_name_snapshot, ''), 'Lainnya') AS category_name")
            ->selectRaw("COALESCE(NULLIF(portion_category, ''), 'small') AS portion_category")
            ->selectRaw("COALESCE(NULLIF(menu_audience, ''), 'student') AS menu_audience")
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('category_id', 'category_code', 'category_name', 'portion_category', 'menu_audience')
            ->orderBy('category_name')
            ->get();

        $categories = $rows->map(fn ($row): array => [
            'beneficiary_category_id' => (int) $row->category_id ?: null,
            'code' => (string) $row->category_code,
            'name' => (string) $row->category_name,
            'portion_category' => (string) $row->portion_category,
            'menu_audience' => (string) $row->menu_audience,
            'total' => (int) $row->total,
        ])->values()->all();

        $small = (int) $rows->where('portion_category', 'small')->sum('total');
        $large = (int) $rows->where('portion_category', 'large')->sum('total');
        $other = max(0, (int) $rows->sum('total') - $small - $large);
        $small += $other;

        $bufferedSmall = (int) ceil($small * (1 + ($bufferPercent / 100)));
        $bufferedLarge = (int) ceil($large * (1 + ($bufferPercent / 100)));

        return [
            'period_id' => $period->getKey(),
            'period_code' => $period->code,
            'period_name' => $period->name,
            'period_status' => $period->status,
            'period_start' => $period->start_date?->toDateString(),
            'period_end' => $period->end_date?->toDateString(),
            'destination_count' => (int) $period->destination_count,
            'active_members' => (int) $rows->sum('total'),
            'buffer_percent' => $bufferPercent,
            'base_small_portions' => $small,
            'base_large_portions' => $large,
            'buffered_small_portions' => $bufferedSmall,
            'buffered_large_portions' => $bufferedLarge,
            'base_total_portions' => $small + $large,
            'buffered_total_portions' => $bufferedSmall + $bufferedLarge,
            'categories' => $categories,
        ];
    }

    public function createCycle(
        int $unitId,
        int $periodId,
        string $name,
        mixed $startDate,
        int $cycleLength,
        float $bufferPercent,
        User $user,
    ): MenuCycle {
        $minimum = (int) config('nutrition_menu.minimum_cycle_length_days', 1);
        $maximum = (int) config('nutrition_menu.maximum_cycle_length_days', 60);
        $cycleLength = max($minimum, min($maximum, $cycleLength));

        $period = BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $unitId)
            ->whereKey($periodId)
            ->firstOrFail();

        if (! in_array($period->status, ['approved', 'active', 'closed'], true)) {
            throw ValidationException::withMessages([
                'period' => 'Data penerima harus sudah disetujui sebelum dipakai untuk menyusun siklus menu.',
            ]);
        }

        $summary = $this->summary($period, $bufferPercent);

        return DB::transaction(function () use (
            $unitId,
            $period,
            $name,
            $startDate,
            $cycleLength,
            $summary,
            $user,
        ): MenuCycle {
            $cycle = MenuCycle::query()->create([
                'sppg_unit_id' => $unitId,
                'beneficiary_period_id' => $period->getKey(),
                'name' => trim($name),
                'start_date' => $startDate,
                'cycle_length_days' => $cycleLength,
                'buffer_percent' => $summary['buffer_percent'],
                'base_small_portions' => $summary['base_small_portions'],
                'base_large_portions' => $summary['base_large_portions'],
                'buffered_small_portions' => $summary['buffered_small_portions'],
                'buffered_large_portions' => $summary['buffered_large_portions'],
                'beneficiary_breakdown' => $summary,
                'beneficiary_snapshot_at' => now(),
                'meal_type' => 'lunch',
                'status' => NutritionRecordStatus::Draft,
                'created_by' => $user->getKey(),
            ]);

            app(MenuCycleService::class)->rebuildDays($cycle);

            return $cycle->refresh();
        });
    }

    public function refreshCycleSnapshot(MenuCycle $cycle): MenuCycle
    {
        if (! $cycle->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Data penerima tidak dapat dihitung ulang setelah siklus diajukan.',
            ]);
        }

        $period = $cycle->beneficiaryPeriod;

        if (! $period) {
            throw ValidationException::withMessages([
                'period' => 'Siklus belum terhubung dengan data penerima.',
            ]);
        }

        $summary = $this->summary($period, (float) $cycle->buffer_percent);
        $cycle->update([
            'base_small_portions' => $summary['base_small_portions'],
            'base_large_portions' => $summary['base_large_portions'],
            'buffered_small_portions' => $summary['buffered_small_portions'],
            'buffered_large_portions' => $summary['buffered_large_portions'],
            'beneficiary_breakdown' => $summary,
            'beneficiary_snapshot_at' => now(),
        ]);

        Menu::query()
            ->whereHas('cycleDays', fn ($query) => $query->where('menu_cycle_id', $cycle->getKey()))
            ->whereIn('status', ['draft', 'revision_required'])
            ->update(['planned_portions' => $summary['buffered_total_portions']]);

        return $cycle->refresh();
    }

    private function normalizeBuffer(float $bufferPercent): float
    {
        $minimum = (float) config('nutrition_menu.minimum_buffer_percent', 0);
        $maximum = (float) config('nutrition_menu.maximum_buffer_percent', 20);

        return round(max($minimum, min($maximum, $bufferPercent)), 2);
    }
}
