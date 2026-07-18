<?php

namespace App\Services;

use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Nama class dipertahankan agar patch lama tetap kompatibel.
 * Sumber data saat ini adalah snapshot Master Penerima Per Periode,
 * bukan lagi modul Konfirmasi Aktual Harian.
 */
class FieldPlanActualConfirmationService
{
    public function readyPeriod(int $unitId, string|CarbonInterface $serviceDate): BeneficiaryPeriod
    {
        if (blank($serviceDate)) {
            throw new DomainException('Tanggal layanan wajib dipilih.');
        }

        $period = BeneficiaryPeriod::query()
            ->with(['destinations.members'])
            ->where('sppg_unit_id', $unitId)
            ->whereIn('status', ['approved', 'active'])
            ->whereDate('start_date', '<=', $serviceDate)
            ->whereDate('end_date', '>=', $serviceDate)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->first();

        if (! $period) {
            throw new DomainException('Master Penerima Per Periode yang sudah disetujui belum tersedia untuk tanggal layanan tersebut.');
        }

        if ($period->active_members < 1 || $period->destinations->isEmpty()) {
            throw new DomainException('Master periode belum memiliki instansi dan penerima aktif.');
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

                if ($members->isEmpty()) {
                    continue;
                }

                $destination = $this->findDestination($plan, $periodDestination);
                $masterTotal = $members->count();
                $small = $members->where('portion_category', 'small')->count();
                $large = $members->where('portion_category', 'large')->count();

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

                $grouped = $members->groupBy(fn ($member): string => implode('|', [
                    $member->beneficiary_category_id ?: 0,
                    $member->beneficiary_category_code_snapshot,
                    $member->portion_category,
                    $member->menu_audience,
                ]));

                foreach ($grouped as $group) {
                    $first = $group->first();
                    $count = $group->count();
                    $destination->recipientGroups()->create([
                        'beneficiary_category_id' => $first->beneficiary_category_id,
                        'beneficiary_category_code_snapshot' => $first->beneficiary_category_code_snapshot,
                        'beneficiary_category_name_snapshot' => $first->beneficiary_category_name_snapshot ?: 'Tanpa Kelompok',
                        'menu_audience' => $first->menu_audience ?: 'student',
                        'portion_size' => $first->portion_category ?: 'small',
                        'registered_beneficiaries' => $count,
                        'confirmed_beneficiaries' => $count,
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
            return ['Master Penerima Per Periode belum dimuat ke Rencana H-3.'];
        }

        if ((int) $plan->beneficiary_period_id !== (int) $period->getKey()) {
            return ['Rencana belum menggunakan Master Penerima yang berlaku pada tanggal distribusi. Muat ulang data master.'];
        }

        $timestamps = collect([
            $period->updated_at,
            $period->destinations()->max('updated_at'),
            $period->members()->max('updated_at'),
        ])->filter()->map(fn ($value): int => \Illuminate\Support\Carbon::parse($value)->getTimestamp());
        $latestUpdate = $timestamps->isNotEmpty() ? $timestamps->max() : null;

        if ($latestUpdate && $plan->actual_data_synced_at->getTimestamp() < $latestUpdate) {
            return ['Snapshot periode berubah setelah rencana dibuat. Klik “Ambil Ulang Master Periode”.'];
        }

        $sourceIds = $plan->destinations()->whereNotNull('beneficiary_period_destination_id')
            ->pluck('beneficiary_period_destination_id')->sort()->values();
        $periodIds = $period->destinations->where('is_active', true)
            ->filter(fn ($destination): bool => $destination->members->where('is_active', true)->isNotEmpty())
            ->pluck('id')->sort()->values();

        if ($sourceIds->all() !== $periodIds->all()) {
            return ['Daftar instansi belum sama dengan snapshot Master Periode. Muat ulang data master.'];
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
            ?? tap(new FieldDistributionPlanDestination(), function ($destination) use ($plan): void {
                $destination->field_distribution_plan_id = $plan->getKey();
            });
    }
}
