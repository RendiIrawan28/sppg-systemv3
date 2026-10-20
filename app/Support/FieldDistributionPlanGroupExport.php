<?php

namespace App\Support;

use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanRecipientGroup;
use Illuminate\Support\Collection;

/** Read-only presentation of the recipient-group snapshots saved with a field plan. */
final class FieldDistributionPlanGroupExport
{
    public static function name(FieldDistributionPlanRecipientGroup $group): string
    {
        return trim((string) ($group->beneficiary_category_name_snapshot
            ?: $group->beneficiary_category_code_snapshot)) ?: 'Kelompok tidak diketahui';
    }

    public static function portionLabel(FieldDistributionPlanRecipientGroup $group): string
    {
        return match ($group->portion_size) {
            'small' => 'Kecil',
            'large' => 'Besar',
            default => '-',
        };
    }

    public static function destinationSummary(Collection $groups): string
    {
        if ($groups->isEmpty()) {
            return 'Rincian kelompok belum tersedia';
        }

        return $groups->map(fn (FieldDistributionPlanRecipientGroup $group): string => sprintf(
            '%s (%s): %s',
            self::name($group),
            self::portionLabel($group),
            number_format((int) $group->total_portions, 0, ',', '.'),
        ))->implode('; ');
    }

    /**
     * @return Collection<int, array{name: string, code: string, portion_size: string, portion_label: string,
     *     registered: int, confirmed: int, small: int, large: int, total: int}>
     */
    public static function totalsByGroup(FieldDistributionPlan $plan): Collection
    {
        $groups = $plan->destinations->flatMap(
            fn ($destination) => $destination->recipientGroups,
        );

        // Combine identical saved category snapshots and portion sizes, not just broad small/large totals.
        return $groups->groupBy(fn (FieldDistributionPlanRecipientGroup $group): string => json_encode([
            (string) $group->beneficiary_category_code_snapshot,
            self::name($group),
            (string) $group->portion_size,
        ], JSON_UNESCAPED_UNICODE))->map(function (Collection $items): array {
            $first = $items->first();

            return [
                'name' => self::name($first),
                'code' => (string) $first->beneficiary_category_code_snapshot,
                'portion_size' => (string) $first->portion_size,
                'portion_label' => self::portionLabel($first),
                'registered' => (int) $items->sum('registered_beneficiaries'),
                'confirmed' => (int) $items->sum('confirmed_beneficiaries'),
                'small' => (int) $items->sum('small_portions'),
                'large' => (int) $items->sum('large_portions'),
                'total' => (int) $items->sum('total_portions'),
            ];
        })->sortBy(fn (array $row): string => mb_strtolower($row['name'].'|'.$row['portion_size']))
            ->values();
    }
}
