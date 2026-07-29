<?php

namespace App\Services;

use App\Enums\DistributionStopStatus;
use App\Models\ContainerCollectionItem;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\DistributionStop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContainerCollectionWorkflow
{
    public function syncTaskFromStop(DistributionStop $stop): ?ContainerCollectionTask
    {
        return DB::transaction(function () use ($stop): ?ContainerCollectionTask {
            $stop = DistributionStop::query()->with('distributionRun')->lockForUpdate()->findOrFail($stop->getKey());
            $terminalSuccessful = in_array($stop->status, [
                DistributionStopStatus::Delivered,
                DistributionStopStatus::Partial,
            ], true);
            $target = max(0, (int) $stop->containers_sent);
            $existing = ContainerCollectionTask::query()
                ->where('distribution_stop_id', $stop->getKey())
                ->lockForUpdate()
                ->first();

            if (! $terminalSuccessful || $target <= 0) {
                if ($existing && (int) $existing->collected_containers === 0) {
                    $existing->delete();
                }

                return $existing;
            }

            $collected = (int) ($existing?->collected_containers ?? 0);
            $target = max($target, $collected);
            $remaining = max(0, $target - $collected);

            return ContainerCollectionTask::updateOrCreate(
                ['distribution_stop_id' => $stop->getKey()],
                [
                    'sppg_unit_id' => $stop->distributionRun->sppg_unit_id,
                    'distribution_run_id' => $stop->distribution_run_id,
                    'delivery_date' => $stop->distributionRun->distribution_date,
                    'destination_name' => $stop->destination_name,
                    'destination_type' => $stop->destination_type,
                    'address' => $stop->address,
                    'contact_name' => $stop->contact_name,
                    'contact_phone' => $stop->contact_phone,
                    'target_containers' => $target,
                    'collected_containers' => $collected,
                    'remaining_containers' => $remaining,
                    'status' => $remaining <= 0
                        ? ContainerCollectionTask::COLLECTED
                        : ($collected > 0 ? ContainerCollectionTask::PARTIAL : ContainerCollectionTask::PENDING),
                    'available_at' => $stop->arrived_at ?: now(),
                    'completed_at' => $remaining <= 0 ? ($existing?->completed_at ?: now()) : null,
                    'notes' => $stop->notes,
                ],
            );
        });
    }

    /** @param array<string, mixed> $data */
    public function startRun(int $unitId, User $actor, array $data = []): ContainerCollectionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($unitId, $actor, $data): ContainerCollectionRun {
            $hasActive = ContainerCollectionRun::query()
                ->where('sppg_unit_id', $unitId)
                ->where('driver_id', $actor->getKey())
                ->where('state', ContainerCollectionRun::ACTIVE)
                ->lockForUpdate()
                ->exists();

            if ($hasActive) {
                throw ValidationException::withMessages([
                    'run' => 'Anda masih memiliki kegiatan pengambilan ompreng yang aktif.',
                ]);
            }

            $hasPendingTasks = ContainerCollectionTask::query()
                ->where('sppg_unit_id', $unitId)
                ->whereIn('status', [ContainerCollectionTask::PENDING, ContainerCollectionTask::PARTIAL])
                ->where('remaining_containers', '>', 0)
                ->exists();

            if (! $hasPendingTasks) {
                throw ValidationException::withMessages([
                    'run' => 'Belum ada sekolah atau Posyandu yang menunggu pengambilan ompreng.',
                ]);
            }

            return ContainerCollectionRun::create([
                'sppg_unit_id' => $unitId,
                'collection_date' => today(),
                'state' => ContainerCollectionRun::ACTIVE,
                'driver_id' => $actor->getKey(),
                'driver_name_snapshot' => $actor->name,
                'kernet_name' => trim((string) ($data['kernet_name'] ?? '')) ?: null,
                'vehicle_name' => trim((string) ($data['vehicle_name'] ?? '')) ?: null,
                'vehicle_plate' => strtoupper(trim((string) ($data['vehicle_plate'] ?? ''))) ?: null,
                'started_at' => now(),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
        });
    }

    public function collectAll(
        ContainerCollectionRun $run,
        ContainerCollectionTask $task,
        User $actor,
        ?string $photoPath = null,
    ): ContainerCollectionItem {
        return $this->collect($run, $task, $actor, (int) $task->remaining_containers, null, $photoPath);
    }

    public function collectPartial(
        ContainerCollectionRun $run,
        ContainerCollectionTask $task,
        User $actor,
        int $quantity,
        string $notes,
        ?string $photoPath = null,
    ): ContainerCollectionItem {
        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan wajib diisi untuk pengambilan sebagian.',
            ]);
        }

        return $this->collect($run, $task, $actor, $quantity, $notes, $photoPath);
    }

    public function returnToSppg(ContainerCollectionRun $run, User $actor): ContainerCollectionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        $run = DB::transaction(function () use ($run, $actor): ContainerCollectionRun {
            $run = ContainerCollectionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOwner($run, $actor);

            if ($run->state !== ContainerCollectionRun::ACTIVE) {
                throw ValidationException::withMessages([
                    'run' => 'Kegiatan pengambilan ini sudah ditutup.',
                ]);
            }

            $total = (int) $run->items()->sum('collected_quantity');
            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'run' => 'Minimal satu tujuan harus sudah diambil sebelum kembali ke SPPG.',
                ]);
            }

            $run->update([
                'state' => ContainerCollectionRun::RETURNED,
                'returned_at' => now(),
                'total_collected' => $total,
            ]);

            return $run->refresh();
        });

        app(OperationalHandoverFlow::class)->createWashingSessionFromCollection($run, $actor);

        return $run->refresh();
    }

    private function collect(
        ContainerCollectionRun $run,
        ContainerCollectionTask $task,
        User $actor,
        int $quantity,
        ?string $notes,
        ?string $photoPath,
    ): ContainerCollectionItem {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $task, $actor, $quantity, $notes, $photoPath): ContainerCollectionItem {
            $run = ContainerCollectionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $task = ContainerCollectionTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOwner($run, $actor);

            if ((int) $task->sppg_unit_id !== (int) $run->sppg_unit_id) {
                throw ValidationException::withMessages([
                    'task' => 'Tujuan pengambilan bukan milik Unit SPPG kegiatan ini.',
                ]);
            }

            if ($run->state !== ContainerCollectionRun::ACTIVE) {
                throw ValidationException::withMessages(['run' => 'Kegiatan pengambilan sudah ditutup.']);
            }

            $remaining = (int) $task->remaining_containers;
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah yang diambil harus lebih dari nol dan tidak boleh melebihi sisa target.',
                ]);
            }

            $newCollected = (int) $task->collected_containers + $quantity;
            $newRemaining = max(0, (int) $task->target_containers - $newCollected);
            $status = $newRemaining === 0
                ? ContainerCollectionTask::COLLECTED
                : ContainerCollectionTask::PARTIAL;

            $item = $run->items()->create([
                'container_collection_task_id' => $task->getKey(),
                'collected_quantity' => $quantity,
                'status' => $status,
                'collected_by' => $actor->getKey(),
                'collected_at' => now(),
                'photo_path' => $photoPath,
                'notes' => filled($notes) ? trim($notes) : null,
            ]);

            $task->update([
                'collected_containers' => $newCollected,
                'remaining_containers' => $newRemaining,
                'status' => $status,
                'completed_at' => $newRemaining === 0 ? now() : null,
            ]);

            $run->update([
                'total_collected' => (int) $run->items()->sum('collected_quantity'),
            ]);

            return $item->refresh();
        });
    }

    private function assertOwner(ContainerCollectionRun $run, User $actor): void
    {
        if ($actor->can('distribution.approve')) {
            return;
        }

        abort_unless((int) $run->driver_id === (int) $actor->getKey(), 403);
    }
}
