<?php

namespace App\Services;

use App\Models\FieldDistributionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileFieldPlanUpdateService
{
    /** @param array<string, mixed> $data */
    public function update(FieldDistributionPlan $plan, User $actor, array $data): FieldDistributionPlan
    {
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages([
                'plan' => 'Rencana tidak berada pada status yang dapat diubah.',
            ]);
        }

        return DB::transaction(function () use ($plan, $actor, $data): FieldDistributionPlan {
            $plan->forceFill([
                'general_notes' => filled($data['general_notes'] ?? null)
                    ? trim((string) $data['general_notes'])
                    : null,
                'updated_by' => $actor->getKey(),
            ])->save();

            foreach ($data['destinations'] as $destinationData) {
                $destination = $plan->destinations()->findOrFail($destinationData['id']);
                $confirmedTotal = collect($destinationData['recipient_groups'])
                    ->sum(fn (array $group): int => (int) $group['confirmed_beneficiaries']);
                $isNotServed = $confirmedTotal === 0;

                $destination->update([
                    'route_name' => ! $isNotServed && filled($destinationData['route_name'] ?? null)
                        ? trim((string) $destinationData['route_name'])
                        : null,
                    'sequence_order' => (int) $destinationData['sequence_order'],
                    'planned_departure_time' => null,
                    'planned_arrival_time' => null,
                    'planned_departure_at' => null,
                    'planned_arrival_at' => null,
                    'special_notes' => filled($destinationData['special_notes'] ?? null)
                        ? trim((string) $destinationData['special_notes'])
                        : null,
                ]);

                foreach ($destinationData['recipient_groups'] as $groupData) {
                    $group = $destination->recipientGroups()->findOrFail($groupData['id']);
                    $group->update([
                        'confirmed_beneficiaries' => (int) $groupData['confirmed_beneficiaries'],
                        'menu_audience' => $groupData['menu_audience'],
                        'portion_size' => $groupData['portion_size'],
                        'notes' => filled($groupData['notes'] ?? null)
                            ? trim((string) $groupData['notes'])
                            : null,
                    ]);
                }

                $destination->recalculatePortionsFromGroups();
                $destination->refresh();
                $changed = (int) $destination->registered_beneficiaries
                    !== (int) $destination->confirmed_beneficiaries;
                $reasonValue = $isNotServed
                    ? ($destinationData['no_service_reason'] ?? null)
                    : ($destinationData['change_reason'] ?? null);
                $changeReason = filled($reasonValue)
                    ? trim((string) $reasonValue)
                    : null;

                if ($changed && ! $changeReason) {
                    throw ValidationException::withMessages([
                        "destinations.{$destination->getKey()}.change_reason" => "{$destination->destination_name_snapshot}: alasan perubahan atau tidak dilayani wajib diisi.",
                    ]);
                }

                $destination->updateQuietly([
                    'confirmation_status' => $changed ? 'changed' : 'confirmed',
                    'confirmed_at' => now(),
                    'confirmed_by_name' => $actor->name,
                    'change_reason' => $changed ? $changeReason : null,
                ]);
            }

            $plan->recalculateTotals();

            return $plan->refresh()->load('destinations.recipientGroups');
        });
    }
}
