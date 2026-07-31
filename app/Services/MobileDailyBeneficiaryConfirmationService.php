<?php

namespace App\Services;

use App\Models\BeneficiaryPeriodDestination;
use App\Models\DailyBeneficiaryConfirmation;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileDailyBeneficiaryConfirmationService
{
    public function __construct(
        private readonly FieldPlanActualConfirmationService $actualConfirmation,
        private readonly FieldActualDistributionPlanSyncService $planSync,
    ) {}

    /** @return Collection<int, DailyBeneficiaryConfirmation> */
    public function generateForDate(int $unitId, string $serviceDate, User $actor): Collection
    {
        abort_unless($actor->can('daily_beneficiary_confirmations.create'), 403);

        try {
            $period = $this->actualConfirmation->readyPeriod($unitId, $serviceDate);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'fields.service_date' => $exception->getMessage(),
            ]);
        }

        return DB::transaction(function () use ($period, $unitId, $serviceDate): Collection {
            $records = new Collection();
            $keptConfirmationIds = [];

            foreach ($period->destinations->where('is_active', true)->sortBy('sort_order') as $destination) {
                $groups = $this->groupsForDestination($destination);
                if ($groups->isEmpty()) {
                    continue;
                }

                $confirmation = DailyBeneficiaryConfirmation::query()->firstOrNew([
                    'sppg_unit_id' => $unitId,
                    'beneficiary_period_id' => $period->getKey(),
                    'service_date' => $serviceDate,
                    'destination_type' => $destination->destination_type,
                    'destination_id' => $destination->destination_id,
                ]);

                $confirmation->fill([
                    'destination_name_snapshot' => $destination->destination_name_snapshot,
                    'destination_code_snapshot' => $destination->destination_code_snapshot,
                    'address_snapshot' => $destination->address_snapshot,
                    'contact_name_snapshot' => $destination->contact_name_snapshot,
                    'contact_phone_snapshot' => $destination->contact_phone_snapshot,
                    'status' => ! $confirmation->exists || $confirmation->status === 'cancelled'
                        ? 'draft'
                        : $confirmation->status,
                ])->save();
                $keptConfirmationIds[] = $confirmation->getKey();

                $existing = $confirmation->items()->get()->keyBy(
                    fn ($item): string => $this->groupKey(
                        $item->beneficiary_category_id,
                        $item->beneficiary_category_code_snapshot,
                        $item->portion_category,
                        $item->menu_audience,
                    ),
                );
                $keptIds = [];

                foreach ($groups as $group) {
                    $key = $this->groupKey(
                        $group['beneficiary_category_id'],
                        $group['code'],
                        $group['portion_category'],
                        $group['menu_audience'],
                    );
                    $item = $existing->get($key) ?: $confirmation->items()->make();
                    $master = (int) $group['count'];
                    $actual = $item->exists ? max(0, (int) $item->actual_count) : $master;

                    $item->fill([
                        'beneficiary_category_id' => $group['beneficiary_category_id'],
                        'beneficiary_category_code_snapshot' => $group['code'],
                        'beneficiary_category_name_snapshot' => $group['name'],
                        'portion_category' => $group['portion_category'],
                        'menu_audience' => $group['menu_audience'],
                        'master_count' => $master,
                        'actual_count' => $actual,
                    ])->save();
                    $keptIds[] = $item->getKey();
                }

                $confirmation->items()->whereNotIn('id', $keptIds ?: [0])->delete();
                $records->push($confirmation->refresh()->load('items'));
            }

            DailyBeneficiaryConfirmation::query()
                ->where('sppg_unit_id', $unitId)
                ->where('beneficiary_period_id', $period->getKey())
                ->whereDate('service_date', $serviceDate)
                ->whereNotIn('id', $keptConfirmationIds ?: [0])
                ->get()
                ->each(function (DailyBeneficiaryConfirmation $confirmation): void {
                    if ($confirmation->status === 'draft') {
                        $confirmation->delete();

                        return;
                    }

                    $confirmation->forceFill(['status' => 'cancelled'])->save();
                });

            if ($records->isEmpty()) {
                throw ValidationException::withMessages([
                    'fields.service_date' => 'Periode penerima tidak memiliki tujuan aktif dengan jumlah penerima.',
                ]);
            }

            return $records;
        });
    }

    public function confirm(DailyBeneficiaryConfirmation $confirmation, User $actor): DailyBeneficiaryConfirmation
    {
        abort_unless($actor->can('daily_beneficiary_confirmations.submit'), 403);

        $confirmation = DB::transaction(function () use ($confirmation, $actor): DailyBeneficiaryConfirmation {
            $confirmation = DailyBeneficiaryConfirmation::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($confirmation->getKey());

            if (! $confirmation->isEditable()) {
                throw ValidationException::withMessages([
                    'confirmation' => 'Konfirmasi penerima sudah melewati batas perubahan atau telah dibatalkan.',
                ]);
            }
            if ($confirmation->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Kelompok penerima belum tersedia.',
                ]);
            }

            $changed = false;
            foreach ($confirmation->items as $item) {
                $actual = (int) $item->actual_count;
                if ($actual < 0) {
                    throw ValidationException::withMessages([
                        'items' => "Jumlah aktual {$item->beneficiary_category_name_snapshot} tidak boleh negatif.",
                    ]);
                }
                if ($actual !== (int) $item->master_count) {
                    $changed = true;
                    if (blank($item->change_reason) && filled($confirmation->notes)) {
                        $item->forceFill(['change_reason' => trim((string) $confirmation->notes)])->save();
                    }
                    if (blank($item->fresh()->change_reason)) {
                        throw ValidationException::withMessages([
                            'items' => "Alasan perubahan {$item->beneficiary_category_name_snapshot} wajib diisi, atau isi Catatan pada konfirmasi tujuan.",
                        ]);
                    }
                }
            }

            $confirmation->forceFill([
                'status' => $changed ? 'changed' : 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by_name' => $actor->name,
            ])->save();

            return $confirmation->refresh()->load('items');
        });

        $pending = DailyBeneficiaryConfirmation::query()
            ->where('sppg_unit_id', $confirmation->sppg_unit_id)
            ->where('beneficiary_period_id', $confirmation->beneficiary_period_id)
            ->whereDate('service_date', $confirmation->service_date)
            ->where('status', 'draft')
            ->exists();

        if (! $pending) {
            $this->planSync->syncForConfirmation($confirmation, $actor);
        }

        return $confirmation->refresh()->load('items');
    }

    private function groupsForDestination(BeneficiaryPeriodDestination $destination)
    {
        $members = $destination->members->where('is_active', true);

        $groups = $destination->categoryTotals->isNotEmpty()
            ? $destination->categoryTotals->map(fn ($total): array => [
                'beneficiary_category_id' => $total->beneficiary_category_id,
                'code' => $total->beneficiary_category_code_snapshot,
                'name' => $total->beneficiary_category_name_snapshot,
                'portion_category' => $total->portion_category ?: 'small',
                'menu_audience' => $total->menu_audience ?: 'student',
                'count' => (int) $total->total_beneficiaries,
            ])
            : $members
                ->groupBy(fn ($member): string => $this->groupKey(
                    $member->beneficiary_category_id,
                    $member->beneficiary_category_code_snapshot,
                    $member->portion_category,
                    $member->menu_audience,
                ))
                ->map(function ($group): array {
                    $first = $group->first();

                    return [
                        'beneficiary_category_id' => $first->beneficiary_category_id,
                        'code' => $first->beneficiary_category_code_snapshot,
                        'name' => $first->beneficiary_category_name_snapshot ?: 'Tanpa Kelompok',
                        'portion_category' => $first->portion_category ?: 'small',
                        'menu_audience' => $first->menu_audience ?: 'student',
                        'count' => $group->count(),
                    ];
                })
                ->values();

        return $groups->filter(fn (array $group): bool => $group['count'] > 0)->values();
    }

    private function groupKey(mixed $categoryId, mixed $code, mixed $portion, mixed $audience): string
    {
        return implode('|', [
            (string) ($categoryId ?: 0),
            (string) $code,
            (string) ($portion ?: 'small'),
            (string) ($audience ?: 'student'),
        ]);
    }
}
