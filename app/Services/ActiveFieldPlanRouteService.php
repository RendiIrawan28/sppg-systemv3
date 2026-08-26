<?php

namespace App\Services;

use App\Models\FieldDistributionPlan;
use App\Models\User;
use App\Support\V3\SystemUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActiveFieldPlanRouteService
{
    /** @param array<int, array{id:int, route_name:mixed, sequence_order:mixed}> $destinations */
    public function update(FieldDistributionPlan $plan, User $actor, array $destinations): FieldDistributionPlan
    {
        if (! $actor->can('field_planning.update') || ! app(SystemUnit::class)->owns($plan)) {
            abort(403);
        }

        if (! $plan->canReviseActiveRoutes()) {
            throw ValidationException::withMessages([
                'routes' => 'Rute hanya dapat disesuaikan setelah rencana aktif dan sebelum dipilih atau mulai dimuat oleh driver.',
            ]);
        }

        return DB::transaction(function () use ($plan, $actor, $destinations): FieldDistributionPlan {
            $plan = FieldDistributionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $plan->canReviseActiveRoutes()) {
                throw ValidationException::withMessages([
                    'routes' => 'Rute sudah dipilih atau perjalanan telah dimulai. Muat ulang halaman untuk melihat kondisi terbaru.',
                ]);
            }

            $rows = collect($destinations)->keyBy(fn (array $row): int => (int) $row['id']);
            $servedDestinations = $plan->destinations()
                ->where('total_portions', '>', 0)
                ->lockForUpdate()
                ->get();

            foreach ($servedDestinations as $destination) {
                $row = $rows->get($destination->getKey());
                if (! $row) {
                    throw ValidationException::withMessages([
                        'routes' => "Data rute {$destination->destination_name_snapshot} belum dikirim.",
                    ]);
                }

                $routeName = trim((string) ($row['route_name'] ?? ''));
                if ($routeName === '') {
                    throw ValidationException::withMessages([
                        "destinations.{$destination->getKey()}.route_name" => "Rute {$destination->destination_name_snapshot} wajib dipilih.",
                    ]);
                }

                $destination->update([
                    'route_name' => $routeName,
                    'sequence_order' => max(1, (int) ($row['sequence_order'] ?? 1)),
                ]);
            }

            $duplicates = $plan->destinations()
                ->where('total_portions', '>', 0)
                ->get()
                ->groupBy(fn ($destination): string => trim((string) $destination->route_name))
                ->flatMap(fn ($items, string $routeName) => $items
                    ->groupBy('sequence_order')
                    ->filter(fn ($sameOrder): bool => $sameOrder->count() > 1)
                    ->keys()
                    ->map(fn ($sequence): string => "{$routeName} urutan {$sequence}"));

            if ($duplicates->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'routes' => 'Urutan tujuan dalam satu rute tidak boleh sama: '.$duplicates->implode(', ').'.',
                ]);
            }

            app(FieldOperationalPlanGenerator::class)->generateDistributionRuns($plan->refresh(), $actor);

            $plan->forceFill(['updated_by' => $actor->getKey()])->save();
            $plan->histories()->create([
                'from_status' => $plan->status->value,
                'to_status' => $plan->status->value,
                'actor_id' => $actor->getKey(),
                'actor_name_snapshot' => $actor->name,
                'notes' => 'Susunan rute aktif disesuaikan.',
                'snapshot' => [
                    'plan_number' => $plan->plan_number,
                    'routes' => $plan->destinations()
                        ->where('total_portions', '>', 0)
                        ->orderBy('route_name')
                        ->orderBy('sequence_order')
                        ->get(['id', 'destination_name_snapshot', 'route_name', 'sequence_order'])
                        ->toArray(),
                ],
            ]);

            return $plan->refresh()->load('destinations.recipientGroups');
        });
    }
}
