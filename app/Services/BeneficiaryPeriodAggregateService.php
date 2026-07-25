<?php

namespace App\Services;

use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\Posyandu;
use App\Models\School;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BeneficiaryPeriodAggregateService
{
    /**
     * @param  array<int, array{destination_key: string, counts: array<int|string, int|string|null>}>  $rows
     */
    public function save(BeneficiaryPeriod $period, array $rows, User $actor): BeneficiaryPeriod
    {
        $categories = BeneficiaryCategory::query()
            ->where('sppg_unit_id', $period->sppg_unit_id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (BeneficiaryCategory $category): int => (int) $category->getKey());

        return DB::transaction(function () use ($period, $rows, $actor, $categories): BeneficiaryPeriod {
            $keptDestinationIds = [];
            $grandTotal = 0;
            $fromStatus = $period->status;

            foreach (array_values($rows) as $index => $row) {
                [$type, $id] = $this->parseDestinationKey((string) ($row['destination_key'] ?? ''));
                $institution = $this->institution($period, $type, $id);
                $destinationKey = "{$type}:{$id}";

                $destination = $period->destinations()
                    ->where('destination_key', $destinationKey)
                    ->first() ?? new BeneficiaryPeriodDestination([
                        'beneficiary_period_id' => $period->getKey(),
                        'destination_key' => $destinationKey,
                    ]);

                $destination->fill([
                    'destination_type' => $type,
                    'destination_id' => $institution->getKey(),
                    'destination_code_snapshot' => $institution->code ?? null,
                    'destination_name_snapshot' => $institution->name,
                    'address_snapshot' => $institution->address ?? null,
                    'contact_name_snapshot' => $institution->pic_name ?? null,
                    'contact_phone_snapshot' => $institution->pic_phone ?? null,
                    'latitude_snapshot' => $institution->latitude ?? null,
                    'longitude_snapshot' => $institution->longitude ?? null,
                    'preferred_delivery_time' => $institution->receiving_time ?? null,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ])->save();

                $keptDestinationIds[] = $destination->getKey();
                $keptCategoryIds = [];

                foreach (($row['counts'] ?? []) as $categoryId => $value) {
                    $category = $categories->get((int) $categoryId);
                    $total = max(0, (int) ($value ?: 0));

                    if (! $category || $total < 1) {
                        continue;
                    }

                    $destination->categoryTotals()->updateOrCreate(
                        ['beneficiary_category_id' => $category->getKey()],
                        [
                            'beneficiary_period_id' => $period->getKey(),
                            'beneficiary_category_code_snapshot' => $category->code,
                            'beneficiary_category_name_snapshot' => $category->name,
                            'portion_category' => $category->portion_size ?: 'small',
                            'menu_audience' => $category->menu_audience ?: 'student',
                            'total_beneficiaries' => $total,
                        ],
                    );
                    $keptCategoryIds[] = $category->getKey();
                    $grandTotal += $total;
                }

                $destination->categoryTotals()
                    ->when($keptCategoryIds !== [], fn ($query) => $query->whereNotIn('beneficiary_category_id', $keptCategoryIds))
                    ->when($keptCategoryIds === [], fn ($query) => $query)
                    ->delete();
            }

            if ($grandTotal < 1) {
                throw new DomainException('Minimal satu kategori penerima harus memiliki jumlah lebih dari nol.');
            }

            $period->destinations()
                ->when($keptDestinationIds !== [], fn ($query) => $query->whereNotIn('id', $keptDestinationIds))
                ->get()
                ->each(function (BeneficiaryPeriodDestination $destination): void {
                    $destination->categoryTotals()->delete();
                    $destination->update(['is_active' => false]);
                });

            $period->forceFill([
                'status' => 'active',
                'destination_count' => count($keptDestinationIds),
                'total_members' => $grandTotal,
                'active_members' => $grandTotal,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
                'closed_at' => null,
            ])->save();

            $period->histories()->create([
                'user_id' => $actor->getKey(),
                'action' => 'save_aggregate_counts',
                'from_status' => $fromStatus,
                'to_status' => 'active',
                'notes' => '',
                'metadata' => [
                    'destination_count' => count($keptDestinationIds),
                    'total_beneficiaries' => $grandTotal,
                ],
            ]);

            return $period->refresh();
        });
    }

    /** @return array{0: string, 1: int} */
    private function parseDestinationKey(string $key): array
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

        if (! in_array($type, ['school', 'posyandu'], true) || ! is_numeric($id)) {
            throw new DomainException('Sekolah atau Posyandu pada salah satu baris belum valid.');
        }

        return [$type, (int) $id];
    }

    private function institution(BeneficiaryPeriod $period, string $type, int $id): Model
    {
        $model = $type === 'school' ? School::class : Posyandu::class;

        return $model::query()
            ->where('sppg_unit_id', $period->sppg_unit_id)
            ->where('is_active', true)
            ->findOrFail($id);
    }
}
