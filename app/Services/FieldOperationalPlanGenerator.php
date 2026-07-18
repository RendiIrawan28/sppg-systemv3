<?php

namespace App\Services;

use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FieldOperationalPlanGenerator
{
    public function generate(FieldDistributionPlan $plan, User $actor): array
    {
        $plan->load(['destinations', 'menuCycleDay']);

        if ($plan->destinations->isEmpty()) {
            throw new RuntimeException('Rencana tidak memiliki tujuan distribusi.');
        }

        return DB::transaction(function () use ($plan, $actor): array {
            $batch = $this->syncProcessingBatch($plan, $actor);
            $portioning = $this->syncPortioningSession($plan, $batch, $actor);
            $distribution = $this->syncDistributionRun($plan, $portioning, $actor);

            $plan->forceFill([
                'processing_batch_id' => $batch?->getKey(),
                'portioning_session_id' => $portioning?->getKey(),
                'distribution_run_id' => $distribution?->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return [
                'processing_batch' => $batch?->batch_number,
                'portioning_session' => $portioning?->session_number,
                'distribution_run' => $distribution?->run_number,
                'skipped' => array_values(array_filter([
                    $batch ? null : 'Modul Pengolahan belum terpasang.',
                    $portioning ? null : 'Modul Pemorsian belum terpasang.',
                    $distribution ? null : 'Modul Distribusi belum terpasang.',
                ])),
            ];
        });
    }

    private function syncProcessingBatch(FieldDistributionPlan $plan, User $actor): ?ProcessingBatch
    {
        if (! class_exists(ProcessingBatch::class)
            || ! Schema::hasTable('processing_batches')
            || ! Schema::hasColumn('processing_batches', 'field_distribution_plan_id')) {
            return null;
        }

        $batch = ProcessingBatch::query()
            ->where('field_distribution_plan_id', $plan->getKey())
            ->first();
        $isNew = ! $batch;
        $batch ??= new ProcessingBatch();

        if (! $isNew && ! $this->canSynchronize($batch)) {
            return $batch;
        }

        $day = $plan->menuCycleDay;
        $isRapel = (bool) ($plan->is_rapel ?? $day?->is_rapel ?? false);
        $productionDate = $plan->production_date
            ?: $day?->production_date
            ?: $plan->distribution_date;

        $attributes = [
            'sppg_unit_id' => $plan->sppg_unit_id,
            'field_distribution_plan_id' => $plan->getKey(),
            'production_date' => $productionDate,
            'menu_name_snapshot' => $plan->menu_name_snapshot,
            'product_name' => $plan->menu_name_snapshot,
            'target_output_quantity' => $plan->planned_total_portions,
            'target_output_unit' => 'porsi',
            // Petugas operasional ditetapkan oleh divisi saat pekerjaan dimulai.
            'petugas_id' => null,
            'petugas_name_snapshot' => null,
            'notes' => $isRapel
                ? sprintf(
                    'Batch rapel terpisah untuk pelayanan %s dari rencana distribusi %s.',
                    ($plan->service_date ?: $plan->distribution_date)?->format('d-m-Y'),
                    $plan->plan_number,
                )
                : "Dibuat dari rencana distribusi {$plan->plan_number}.",
            'updated_by' => $actor->getKey(),
        ];

        if (Schema::hasColumn('processing_batches', 'menu_cycle_day_id')) {
            $attributes['menu_cycle_day_id'] = $day?->getKey();
        }

        if (Schema::hasColumn('processing_batches', 'service_date')) {
            $attributes['service_date'] = $plan->service_date ?: $plan->distribution_date;
        }

        if (Schema::hasColumn('processing_batches', 'is_rapel')) {
            $attributes['is_rapel'] = $isRapel;
        }

        if (Schema::hasColumn('processing_batches', 'batch_type')) {
            $attributes['batch_type'] = $isRapel ? 'rapel_saturday' : 'regular';
        }

        if ($isNew) {
            $attributes['created_by'] = $actor->getKey();
        }

        if (Schema::hasColumn('processing_batches', 'menu_id')) {
            $attributes['menu_id'] = $plan->menu_id;
        }

        $batch->forceFill($attributes)->save();

        return $batch->refresh();
    }

    private function syncPortioningSession(
        FieldDistributionPlan $plan,
        ?ProcessingBatch $batch,
        User $actor,
    ): ?PortioningSession {
        if (! class_exists(PortioningSession::class)
            || ! Schema::hasTable('portioning_sessions')
            || ! Schema::hasColumn('portioning_sessions', 'field_distribution_plan_id')) {
            return null;
        }

        $session = PortioningSession::query()
            ->where('field_distribution_plan_id', $plan->getKey())
            ->first();
        $isNew = ! $session;
        $session ??= new PortioningSession();

        if (! $isNew && ! $this->canSynchronize($session)) {
            return $session;
        }

        $attributes = [
            'sppg_unit_id' => $plan->sppg_unit_id,
            'field_distribution_plan_id' => $plan->getKey(),
            'processing_batch_id' => $batch?->getKey(),
            'portioning_date' => $plan->distribution_date,
            'menu_name_snapshot' => $plan->menu_name_snapshot,
            'target_small_portions' => $plan->planned_small_portions,
            'target_large_portions' => $plan->planned_large_portions,
            // Petugas operasional ditetapkan oleh divisi saat pekerjaan dimulai.
            'petugas_id' => null,
            'petugas_name_snapshot' => null,
            'notes' => "Dibuat dari rencana distribusi {$plan->plan_number}.",
            'updated_by' => $actor->getKey(),
        ];

        if ($isNew) {
            $attributes['created_by'] = $actor->getKey();
        }

        $session->forceFill($attributes)->save();

        if (Schema::hasTable('portioning_route_allocations')) {
            $session->routeAllocations()->delete();

            foreach ($plan->destinations as $destination) {
                $allocation = $session->routeAllocations()->make([
                    'route_name' => $destination->route_name ?: $destination->destination_name_snapshot,
                    'destination_name' => $destination->destination_name_snapshot,
                    'destination_type' => $destination->destination_type,
                    'address' => $destination->address_snapshot,
                    'contact_name' => $destination->contact_name_snapshot,
                    'contact_phone' => $destination->contact_phone_snapshot,
                    'planned_arrival_at' => $this->plannedDateTime($plan, $destination, 'planned_arrival_time', 'planned_arrival_at'),
                    'planned_departure_at' => $this->plannedDateTime($plan, $destination, 'planned_departure_time', 'planned_departure_at'),
                    'latitude' => $destination->latitude_snapshot,
                    'longitude' => $destination->longitude_snapshot,
                    'target_small_portions' => $destination->small_portions,
                    'target_large_portions' => $destination->large_portions,
                    'actual_small_portions' => 0,
                    'actual_large_portions' => 0,
                    'sort_order' => $destination->sequence_order,
                    'notes' => "Tujuan: {$destination->destination_name_snapshot}",
                ]);

                if (Schema::hasColumn('portioning_route_allocations', 'field_distribution_plan_destination_id')) {
                    $allocation->field_distribution_plan_destination_id = $destination->getKey();
                }

                $allocation->save();
            }

            $session->recalculateTotals();
        }

        return $session->refresh();
    }

    private function syncDistributionRun(
        FieldDistributionPlan $plan,
        ?PortioningSession $portioning,
        User $actor,
    ): ?DistributionRun {
        if (! class_exists(DistributionRun::class)
            || ! Schema::hasTable('distribution_runs')
            || ! Schema::hasColumn('distribution_runs', 'field_distribution_plan_id')) {
            return null;
        }

        $run = DistributionRun::query()
            ->where('field_distribution_plan_id', $plan->getKey())
            ->first();
        $isNew = ! $run;
        $run ??= new DistributionRun();

        if (! $isNew && ! $this->canSynchronize($run)) {
            return $run;
        }

        $attributes = [
            'sppg_unit_id' => $plan->sppg_unit_id,
            'field_distribution_plan_id' => $plan->getKey(),
            'portioning_session_id' => $portioning?->getKey(),
            'distribution_date' => $plan->distribution_date,
            'menu_name_snapshot' => $plan->menu_name_snapshot,
            'planned_small_portions' => $plan->planned_small_portions,
            'planned_large_portions' => $plan->planned_large_portions,
            'planned_departure_at' => $this->earliestDepartureAt($plan),
            // Petugas operasional ditetapkan oleh divisi saat pekerjaan dimulai.
            'petugas_id' => null,
            'petugas_name_snapshot' => null,
            'notes' => "Dibuat dari rencana distribusi {$plan->plan_number}.",
            'updated_by' => $actor->getKey(),
        ];

        if ($isNew) {
            $attributes['created_by'] = $actor->getKey();
        }

        $run->forceFill($attributes)->save();

        if (Schema::hasTable('distribution_stops')) {
            $run->stops()->delete();

            foreach ($plan->destinations as $destination) {
                $stop = $run->stops()->make([
                    'route_name' => $destination->route_name,
                    'destination_name' => $destination->destination_name_snapshot,
                    'destination_type' => $destination->destination_type,
                    'address' => $destination->address_snapshot,
                    'contact_name' => $destination->contact_name_snapshot,
                    'contact_phone' => $destination->contact_phone_snapshot,
                    'sequence_order' => $destination->sequence_order,
                    'planned_arrival_at' => $this->plannedDateTime($plan, $destination, 'planned_arrival_time', 'planned_arrival_at'),
                    'small_portions' => $destination->small_portions,
                    'large_portions' => $destination->large_portions,
                    'containers_sent' => $destination->total_portions,
                    'latitude' => $destination->latitude_snapshot,
                    'longitude' => $destination->longitude_snapshot,
                    'notes' => $destination->special_notes,
                ]);

                if (Schema::hasColumn('distribution_stops', 'field_distribution_plan_destination_id')) {
                    $stop->forceFill([
                        'field_distribution_plan_destination_id' => $destination->getKey(),
                    ]);
                }

                $stop->save();
            }

            $run->recalculateTotals();
        }

        return $run->refresh();
    }

    private function earliestDepartureAt(FieldDistributionPlan $plan): ?Carbon
    {
        return $plan->destinations
            ->map(fn ($destination): ?Carbon => $this->plannedDateTime($plan, $destination, 'planned_departure_time', 'planned_departure_at'))
            ->filter()
            ->sort()
            ->first();
    }

    private function plannedDateTime(
        FieldDistributionPlan $plan,
        object $destination,
        string $timeField,
        string $legacyDateTimeField,
    ): ?Carbon {
        $time = data_get($destination, $timeField);

        if (filled($time)) {
            $timeString = $time instanceof \DateTimeInterface
                ? Carbon::instance($time)->format('H:i:s')
                : Carbon::parse((string) $time)->format('H:i:s');

            return Carbon::parse($plan->distribution_date)->startOfDay()->setTimeFromTimeString($timeString);
        }

        $legacyValue = data_get($destination, $legacyDateTimeField);

        return $legacyValue ? Carbon::parse($legacyValue) : null;
    }

    private function canSynchronize(Model $record): bool
    {
        $status = $this->enumValue($record->getAttribute('status'));
        $state = $this->enumValue($record->getAttribute('state'));

        return in_array($status, ['draft', 'revision_required'], true)
            && $state === 'planned';
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
