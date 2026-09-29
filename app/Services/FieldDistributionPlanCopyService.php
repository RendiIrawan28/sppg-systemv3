<?php

namespace App\Services;

use App\Enums\FieldDistributionPlanStatus;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\FieldDistributionPlanRecipientGroup;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class FieldDistributionPlanCopyService
{
    /** @return array<int, array<string, int|string>> */
    public function availableSources(int $unitId): array
    {
        return collect(['today' => 'Hari ini', 'yesterday' => 'Kemarin'])
            ->map(function (string $label, string $day) use ($unitId): ?array {
                $plan = $this->sourceForDay($unitId, $day);
                if (! $plan) {
                    return null;
                }

                return [
                    'day' => $day,
                    'label' => $label,
                    'date' => $plan->distribution_date->toDateString(),
                    'plan_number' => (string) $plan->plan_number,
                    'destination_count' => (int) $plan->destination_count,
                    'beneficiary_count' => (int) $plan->confirmed_beneficiaries,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array{copied_destinations: int, unmatched_destinations: int} */
    public function copyToPlan(FieldDistributionPlan $plan, User $actor, string $sourceDay): array
    {
        if (! in_array($sourceDay, ['today', 'yesterday'], true)) {
            throw ValidationException::withMessages(['copy_source_day' => 'Pilih rencana hari ini atau kemarin.']);
        }
        if (! $plan->exists || ! $plan->isEditable()) {
            throw ValidationException::withMessages(['copy_source_day' => 'Salinan hanya dapat diterapkan pada rencana draft.']);
        }

        $source = $this->sourceForDay((int) $plan->sppg_unit_id, $sourceDay);
        if (! $source) {
            throw ValidationException::withMessages(['copy_source_day' => 'Rencana sumber tidak ditemukan atau sudah dibatalkan.']);
        }
        if ($source->distribution_date->greaterThanOrEqualTo($plan->distribution_date)) {
            throw ValidationException::withMessages(['distribution_date' => 'Tanggal rencana baru harus setelah tanggal rencana yang disalin.']);
        }

        $source->load('destinations.recipientGroups');
        $plan->load('destinations.recipientGroups');
        $sourceDestinations = $source->destinations->keyBy(fn (FieldDistributionPlanDestination $item): string => $this->destinationKey($item));
        $copied = 0;

        foreach ($plan->destinations as $destination) {
            $original = $sourceDestinations->get($this->destinationKey($destination));
            if (! $original) {
                continue;
            }

            $sourceGroups = $original->recipientGroups
                ->keyBy(fn (FieldDistributionPlanRecipientGroup $group): string => $this->groupKey($group));
            $matchedGroups = 0;
            foreach ($destination->recipientGroups as $group) {
                $sourceGroup = $sourceGroups->get($this->groupKey($group));
                if (! $sourceGroup) {
                    continue;
                }
                $group->update([
                    'confirmed_beneficiaries' => (int) $sourceGroup->confirmed_beneficiaries,
                    'menu_audience' => $sourceGroup->menu_audience,
                    'portion_size' => $sourceGroup->portion_size,
                    'notes' => $sourceGroup->notes,
                ]);
                $matchedGroups++;
            }
            if ($matchedGroups === 0) {
                continue;
            }

            $destination->refresh();
            $changed = (int) $destination->registered_beneficiaries !== (int) $destination->confirmed_beneficiaries;
            $reason = trim((string) $original->change_reason)
                ?: "Jumlah disalin dari {$source->plan_number}; periksa kesesuaian untuk tanggal baru.";
            $destination->update([
                'route_name' => (int) $destination->total_portions > 0 ? $original->route_name : null,
                'sequence_order' => $original->sequence_order,
                'planned_departure_time' => $original->planned_departure_time,
                'planned_arrival_time' => $original->planned_arrival_time,
                'special_notes' => $original->special_notes,
            ]);
            $destination->updateQuietly([
                'confirmation_status' => $changed ? 'changed' : 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by_name' => $actor->name,
                'change_reason' => $changed ? $reason : null,
            ]);
            $copied++;
        }

        if ($copied === 0) {
            throw ValidationException::withMessages([
                'copy_source_day' => 'Tidak ada sekolah/Posyandu dan kelompok penerima yang cocok dengan rencana sumber. Buat rencana baru dari periode penerima.',
            ]);
        }

        if (blank($plan->general_notes) && filled($source->general_notes)) {
            $plan->forceFill(['general_notes' => $source->general_notes])->save();
        }
        $plan->recalculateTotals();

        return [
            'copied_destinations' => $copied,
            'unmatched_destinations' => $plan->destinations->count() - $copied,
        ];
    }

    private function sourceForDay(int $unitId, string $day): ?FieldDistributionPlan
    {
        if (! in_array($day, ['today', 'yesterday'], true)) {
            return null;
        }

        return FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $day === 'today' ? today() : today()->subDay())
            ->where('status', '!=', FieldDistributionPlanStatus::Cancelled->value)
            ->latest('id')
            ->first();
    }

    private function destinationKey(FieldDistributionPlanDestination $destination): string
    {
        return (string) $destination->destination_type.':'
            .($destination->destination_id ?: $destination->destination_code_snapshot);
    }

    private function groupKey(FieldDistributionPlanRecipientGroup $group): string
    {
        return (string) ($group->beneficiary_category_id ?: $group->beneficiary_category_code_snapshot)
            .':'.(string) $group->menu_audience;
    }
}
