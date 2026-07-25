<?php

namespace App\Services;

use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nama class dipertahankan agar patch lama tetap kompatibel.
 * Sumber data adalah jumlah penerima per sekolah/Posyandu dan kategori.
 * Snapshot lama berbasis nama tetap dibaca untuk kompatibilitas arsip.
 */
class FieldPlanActualConfirmationService
{
    public function readyPeriod(int $unitId, string|CarbonInterface $serviceDate): BeneficiaryPeriod
    {
        if (blank($serviceDate)) {
            throw new DomainException('Tanggal layanan wajib dipilih.');
        }

        $period = BeneficiaryPeriod::query()
            ->with(['destinations.members', 'destinations.categoryTotals'])
            ->where('sppg_unit_id', $unitId)
            ->whereIn('status', ['approved', 'active'])
            ->whereDate('start_date', '<=', $serviceDate)
            ->whereDate('end_date', '>=', $serviceDate)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->first();

        if (! $period) {
            throw new DomainException('Jumlah penerima aktif belum tersedia untuk tanggal layanan tersebut.');
        }

        if ($period->active_members < 1 || $period->destinations->isEmpty()) {
            throw new DomainException('Data jumlah penerima belum memiliki sekolah/Posyandu dan kategori dengan jumlah lebih dari nol.');
        }

        return $period;
    }

    public function synchronize(FieldDistributionPlan $plan, User $actor): array
    {
        if (! $plan->isEditable()) {
            throw new DomainException('Master periode hanya dapat dimuat saat rencana berstatus Draft atau Perlu Revisi.');
        }

        $period = $this->readyPeriod((int) $plan->sppg_unit_id, $plan->service_date ?: $plan->distribution_date);

        return DB::transaction(function () use ($plan, $actor, $period): array {
            $keptDestinationIds = [];
            $smallTotal = 0;
            $largeTotal = 0;
            $actualTotal = 0;

            foreach ($period->destinations->where('is_active', true)->values() as $index => $periodDestination) {
                $members = $periodDestination->members->where('is_active', true);
                $groups = $periodDestination->categoryTotals->isNotEmpty()
                    ? $periodDestination->categoryTotals->map(fn ($total): array => [
                        'beneficiary_category_id' => $total->beneficiary_category_id,
                        'code' => $total->beneficiary_category_code_snapshot,
                        'name' => $total->beneficiary_category_name_snapshot,
                        'portion_size' => $total->portion_category ?: 'small',
                        'menu_audience' => $total->menu_audience ?: 'student',
                        'count' => (int) $total->total_beneficiaries,
                    ])
                    : $members
                        ->groupBy(fn ($member): string => implode('|', [
                            $member->beneficiary_category_id ?: 0,
                            $member->beneficiary_category_code_snapshot,
                            $member->portion_category,
                            $member->menu_audience,
                        ]))
                        ->map(function ($group): array {
                            $first = $group->first();

                            return [
                                'beneficiary_category_id' => $first->beneficiary_category_id,
                                'code' => $first->beneficiary_category_code_snapshot,
                                'name' => $first->beneficiary_category_name_snapshot ?: 'Tanpa Kelompok',
                                'portion_size' => $first->portion_category ?: 'small',
                                'menu_audience' => $first->menu_audience ?: 'student',
                                'count' => $group->count(),
                            ];
                        })
                        ->values();

                $groups = $groups->filter(fn (array $group): bool => $group['count'] > 0)->values();

                if ($groups->isEmpty()) {
                    continue;
                }

                $destination = $this->findDestination($plan, $periodDestination);
                $masterTotal = (int) $groups->sum('count');
                $small = (int) $groups->where('portion_size', 'small')->sum('count');
                $large = (int) $groups->where('portion_size', 'large')->sum('count');

                $destination->fill([
                    'beneficiary_period_destination_id' => $periodDestination->getKey(),
                    'daily_beneficiary_confirmation_id' => null,
                    'destination_type' => $periodDestination->destination_type,
                    'destination_id' => $periodDestination->destination_id,
                    'destination_code_snapshot' => $periodDestination->destination_code_snapshot,
                    'destination_name_snapshot' => $periodDestination->destination_name_snapshot,
                    'address_snapshot' => $periodDestination->address_snapshot,
                    'contact_name_snapshot' => $periodDestination->contact_name_snapshot,
                    'contact_phone_snapshot' => $periodDestination->contact_phone_snapshot,
                    'latitude_snapshot' => $periodDestination->latitude_snapshot,
                    'longitude_snapshot' => $periodDestination->longitude_snapshot,
                    'route_name' => $destination->route_name ?: 'Rute Utama',
                    'sequence_order' => $destination->sequence_order ?: ($index + 1),
                    'registered_beneficiaries' => $masterTotal,
                    'confirmed_beneficiaries' => $masterTotal,
                    'small_portions' => $small,
                    'large_portions' => $large,
                    'total_portions' => $masterTotal,
                    'confirmation_status' => 'confirmed',
                    'confirmed_at' => now(),
                    'confirmed_by_name' => $actor->name,
                    'change_reason' => null,
                ]);
                $destination->save();
                $destination->recipientGroups()->delete();

                foreach ($groups as $group) {
                    $destination->recipientGroups()->create([
                        'beneficiary_category_id' => $group['beneficiary_category_id'],
                        'beneficiary_category_code_snapshot' => $group['code'],
                        'beneficiary_category_name_snapshot' => $group['name'],
                        'menu_audience' => $group['menu_audience'],
                        'portion_size' => $group['portion_size'],
                        'registered_beneficiaries' => $group['count'],
                        'confirmed_beneficiaries' => $group['count'],
                    ]);
                }

                $destination->recalculatePortionsFromGroups();
                $keptDestinationIds[] = $destination->getKey();
                $smallTotal += $small;
                $largeTotal += $large;
                $actualTotal += $masterTotal;
            }

            $plan->destinations()
                ->whereNotNull('beneficiary_period_destination_id')
                ->whereNotIn('id', $keptDestinationIds ?: [0])
                ->get()
                ->each->delete();

            $plan->forceFill([
                'beneficiary_period_id' => $period->getKey(),
                'actual_data_synced_at' => now(),
                'actual_data_synced_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();
            $plan->recalculateTotals();

            return [
                'period_id' => $period->getKey(),
                'period_name' => $period->name,
                'destination_count' => count($keptDestinationIds),
                'confirmed_beneficiaries' => $actualTotal,
                'small_portions' => $smallTotal,
                'large_portions' => $largeTotal,
            ];
        });
    }

    public function synchronizationIssues(FieldDistributionPlan $plan): array
    {
        if (! $plan->distribution_date || ! $plan->sppg_unit_id) {
            return ['Tanggal layanan/distribusi atau Unit SPPG belum valid.'];
        }

        try {
            $period = $this->readyPeriod((int) $plan->sppg_unit_id, $plan->service_date ?: $plan->distribution_date);
        } catch (DomainException $exception) {
            return [$exception->getMessage()];
        }

        if (! $plan->actual_data_synced_at || ! $plan->beneficiary_period_id) {
            return ['Jumlah penerima belum dimuat ke Rencana H-3.'];
        }

        if ((int) $plan->beneficiary_period_id !== (int) $period->getKey()) {
            return ['Rencana belum menggunakan jumlah penerima yang berlaku pada tanggal distribusi. Muat ulang data.'];
        }

        $timestamps = collect([
            $period->updated_at,
            $period->destinations()->max('updated_at'),
            $period->categoryTotals()->max('updated_at'),
            $period->members()->max('updated_at'),
        ])->filter()->map(fn ($value): int => Carbon::parse($value)->getTimestamp());
        $latestUpdate = $timestamps->isNotEmpty() ? $timestamps->max() : null;

        if ($latestUpdate && $plan->actual_data_synced_at->getTimestamp() < $latestUpdate) {
            return ['Jumlah penerima berubah setelah rencana dibuat. Klik “Ambil Ulang Jumlah Penerima”.'];
        }

        $sourceIds = $plan->destinations()->whereNotNull('beneficiary_period_destination_id')
            ->pluck('beneficiary_period_destination_id')->sort()->values();
        $periodIds = $period->destinations->where('is_active', true)
            ->filter(fn ($destination): bool => $destination->categoryTotals->where('total_beneficiaries', '>', 0)->isNotEmpty()
                || $destination->members->where('is_active', true)->isNotEmpty())
            ->pluck('id')->sort()->values();

        if ($sourceIds->all() !== $periodIds->all()) {
            return ['Daftar sekolah/Posyandu belum sama dengan data jumlah penerima. Muat ulang data.'];
        }

        return [];
    }

    private function findDestination(
        FieldDistributionPlan $plan,
        BeneficiaryPeriodDestination $periodDestination,
    ): FieldDistributionPlanDestination {
        return $plan->destinations()
            ->where('beneficiary_period_destination_id', $periodDestination->getKey())
            ->first()
            ?? $plan->destinations()
                ->where('destination_type', $periodDestination->destination_type)
                ->where('destination_id', $periodDestination->destination_id)
                ->first()
            ?? tap(new FieldDistributionPlanDestination, function ($destination) use ($plan): void {
                $destination->field_distribution_plan_id = $plan->getKey();
            });
    }
}
