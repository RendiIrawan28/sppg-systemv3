<?php

namespace App\Services;

use App\Enums\DistributionIncidentSeverity;
use App\Enums\DistributionIncidentStatus;
use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\FieldDistributionPlanStatus;
use App\Enums\OperationalReportStatus;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\DistributionRun;
use App\Models\DistributionStop;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionWorkflow
{
    public function claimRoute(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $run = $this->lockedRun($run);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Rute sudah dipilih atau tidak tersedia lagi.',
                ]);
            }

            $vehicleName = trim((string) ($data['vehicle_name'] ?? ''));
            $vehiclePlate = trim((string) ($data['vehicle_plate'] ?? ''));
            $kernetName = trim((string) ($data['kernet_name'] ?? ''));

            if ($vehicleName === '' || $vehiclePlate === '' || $kernetName === '') {
                throw ValidationException::withMessages([
                    'assignment' => 'Kendaraan, nomor polisi, dan nama kernet wajib diisi sebelum memilih rute.',
                ]);
            }

            $hasActiveCollection = ContainerCollectionRun::query()
                ->where('sppg_unit_id', $run->sppg_unit_id)
                ->where('driver_id', $actor->getKey())
                ->where('state', ContainerCollectionRun::ACTIVE)
                ->exists();

            if ($hasActiveCollection) {
                throw ValidationException::withMessages([
                    'driver' => 'Anda masih memiliki kegiatan pengambilan ompreng yang aktif. Kembali ke SPPG terlebih dahulu.',
                ]);
            }

            $hasActiveRoute = DistributionRun::query()
                ->where('sppg_unit_id', $run->sppg_unit_id)
                ->where('petugas_id', $actor->getKey())
                ->whereIn('state', $this->activeDriverStates())
                ->where('id', '!=', $run->getKey())
                ->lockForUpdate()
                ->exists();

            if ($hasActiveRoute) {
                throw ValidationException::withMessages([
                    'driver' => 'Anda masih memiliki rute aktif. Kembali ke SPPG terlebih dahulu sebelum memilih rute berikutnya.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'vehicle_name' => $vehicleName,
                'vehicle_plate' => strtoupper($vehiclePlate),
                'driver_name' => $actor->name,
                'kernet_name' => $kernetName,
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'assigned_at' => now(),
                'state' => DistributionRunState::Assigned,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($run, $actor, 'route_claimed', $previousState, $run->state->value);

            return $run->refresh();
        });
    }

    public function releaseRoute(DistributionRun $run, User $actor, ?string $notes = null): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);

            if (! in_array($run->state, [DistributionRunState::Assigned, DistributionRunState::Loaded], true)) {
                throw ValidationException::withMessages([
                    'state' => 'Rute hanya dapat dilepas sebelum kendaraan berangkat.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'vehicle_name' => null,
                'vehicle_plate' => null,
                'driver_name' => null,
                'kernet_name' => null,
                'petugas_id' => null,
                'petugas_name_snapshot' => null,
                'assigned_at' => null,
                'loading_started_at' => null,
                'loaded_small_portions' => 0,
                'loaded_large_portions' => 0,
                'state' => DistributionRunState::Planned,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($run, $actor, 'route_released', $previousState, $run->state->value, $notes);

            return $run->refresh();
        });
    }

    public function startLoading(DistributionRun $run, User $actor): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor): DistributionRun {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Assigned) {
                throw ValidationException::withMessages([
                    'state' => 'Rute belum dipilih atau tidak dapat mulai dimuat.',
                ]);
            }

            $run->recalculateTotals();
            $run = $this->lockedRun($run);

            if ($run->stops()->count() === 0) {
                throw ValidationException::withMessages([
                    'stops' => 'Minimal satu tujuan distribusi wajib tersedia.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'loaded_small_portions' => $run->planned_small_portions,
                'loaded_large_portions' => $run->planned_large_portions,
                'loading_started_at' => now(),
                'state' => DistributionRunState::Loaded,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($run, $actor, 'loading_started', $previousState, $run->state->value);

            return $run->refresh();
        });
    }

    /**
     * Kompatibilitas untuk pemanggilan lama/API. Pada alur web baru, driver
     * memilih rute terlebih dahulu kemudian menekan Mulai Memuat.
     */
    public function prepareLoad(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        $run->refresh();

        if ($run->state === DistributionRunState::Planned) {
            $run = $this->claimRoute($run, $actor, $data);
        }

        return $this->startLoading($run, $actor);
    }

    public function depart(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Loaded) {
                throw ValidationException::withMessages([
                    'state' => 'Status rute harus Memuat sebelum kendaraan berangkat.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'actual_departure_at' => $data['actual_departure_at'] ?? now(),
                'departure_temperature_celsius' => $data['departure_temperature_celsius'] ?? null,
                'state' => DistributionRunState::Departed,
                'updated_by' => $actor->getKey(),
            ]);

            $run->stops()
                ->whereIn('status', [
                    DistributionStopStatus::Planned->value,
                    DistributionStopStatus::InTransit->value,
                ])
                ->update(['status' => DistributionStopStatus::InTransit->value]);

            $this->writeHistory($run, $actor, 'departed', $previousState, $run->state->value);

            return $run->refresh();
        });
    }

    public function arriveAtStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor): DistributionStop {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Departed
                || ! in_array($stop->status, [DistributionStopStatus::Planned, DistributionStopStatus::InTransit], true)) {
                throw ValidationException::withMessages([
                    'stop' => 'Tujuan belum dapat ditandai tiba pada kondisi saat ini.',
                ]);
            }

            $stop->update([
                'arrived_at' => now(),
                'status' => DistributionStopStatus::Arrived,
            ]);

            $this->writeHistory(
                $run,
                $actor,
                'arrived_at_destination',
                $run->state->value,
                $run->state->value,
                $stop->destination_name,
            );

            return $stop->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function completeStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
        array $data = [],
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor, $data): DistributionStop {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Departed
                || $stop->status?->isTerminal()) {
                throw ValidationException::withMessages([
                    'stop' => 'Tujuan tidak dapat diselesaikan pada kondisi saat ini.',
                ]);
            }

            $deliveredSmall = (int) ($data['delivered_small_portions'] ?? 0);
            $deliveredLarge = (int) ($data['delivered_large_portions'] ?? 0);
            $recipientName = trim((string) ($data['recipient_name'] ?? ''));
            $recipientPosition = trim((string) ($data['recipient_position'] ?? ''));
            $photoPath = $data['handover_photo_path'] ?? $stop->handover_photo_path;
            $failureReason = trim((string) ($data['failure_reason'] ?? ''));
            $containersSent = (int) ($data['containers_sent'] ?? 0);

            if ($recipientName === '' || blank($photoPath)) {
                throw ValidationException::withMessages([
                    'stop' => 'Nama penerima dan foto dokumentasi serah-terima wajib diisi.',
                ]);
            }

            if ($deliveredSmall < 0 || $deliveredSmall > (int) $stop->small_portions
                || $deliveredLarge < 0 || $deliveredLarge > (int) $stop->large_portions) {
                throw ValidationException::withMessages([
                    'stop' => 'Jumlah porsi yang diserahkan tidak boleh melebihi porsi rencana tujuan.',
                ]);
            }

            $returnedSmall = (int) $stop->small_portions - $deliveredSmall;
            $returnedLarge = (int) $stop->large_portions - $deliveredLarge;
            $isPartial = ($returnedSmall + $returnedLarge) > 0;

            if ($isPartial && $failureReason === '') {
                throw ValidationException::withMessages([
                    'stop' => 'Alasan pengiriman sebagian wajib diisi.',
                ]);
            }

            if (($deliveredSmall + $deliveredLarge) <= 0) {
                throw ValidationException::withMessages([
                    'stop' => 'Gunakan tombol Gagal dikirim jika tidak ada porsi yang diserahkan.',
                ]);
            }

            if ($containersSent <= 0) {
                throw ValidationException::withMessages([
                    'containers_sent' => 'Jumlah ompreng atau wadah yang dikirim wajib lebih dari nol.',
                ]);
            }

            $stop->update([
                'arrived_at' => $stop->arrived_at ?: now(),
                'delivered_small_portions' => $deliveredSmall,
                'delivered_large_portions' => $deliveredLarge,
                'returned_small_portions' => $returnedSmall,
                'returned_large_portions' => $returnedLarge,
                'recipient_name' => $recipientName,
                'recipient_position' => $recipientPosition ?: null,
                'handover_photo_path' => $photoPath,
                'containers_sent' => $containersSent,
                'failure_reason' => $isPartial ? $failureReason : null,
                'status' => $isPartial
                    ? DistributionStopStatus::Partial
                    : DistributionStopStatus::Delivered,
            ]);

            $run->recalculateTotals();
            $this->writeHistory(
                $run,
                $actor,
                $isPartial ? 'partially_delivered' : 'delivered_at_destination',
                $run->state->value,
                $run->state->value,
                $stop->destination_name,
            );
            app(ContainerCollectionWorkflow::class)->syncTaskFromStop($stop->refresh());
            $this->markDestinationsCompletedIfReady($run, $actor);

            return $stop->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function failStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
        array $data = [],
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor, $data): DistributionStop {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);
            $stop = $this->lockedStop($run, $stop);
            $failureReason = trim((string) ($data['failure_reason'] ?? ''));

            if ($run->state !== DistributionRunState::Departed
                || $stop->status?->isTerminal()
                || $failureReason === '') {
                throw ValidationException::withMessages([
                    'stop' => $failureReason === ''
                        ? 'Alasan gagal dikirim wajib diisi.'
                        : 'Tujuan tidak dapat ditandai gagal pada kondisi saat ini.',
                ]);
            }

            $stop->update([
                'arrived_at' => $stop->arrived_at ?: now(),
                'delivered_small_portions' => 0,
                'delivered_large_portions' => 0,
                'returned_small_portions' => $stop->small_portions,
                'returned_large_portions' => $stop->large_portions,
                'recipient_name' => trim((string) ($data['recipient_name'] ?? '')) ?: null,
                'recipient_position' => trim((string) ($data['recipient_position'] ?? '')) ?: null,
                'handover_photo_path' => $data['handover_photo_path'] ?? $stop->handover_photo_path,
                'containers_sent' => 0,
                'failure_reason' => $failureReason,
                'status' => DistributionStopStatus::Failed,
            ]);

            $run->recalculateTotals();
            $this->writeHistory(
                $run,
                $actor,
                'delivery_failed',
                $run->state->value,
                $run->state->value,
                $stop->destination_name.': '.$failureReason,
            );
            app(ContainerCollectionWorkflow::class)->syncTaskFromStop($stop->refresh());
            $this->markDestinationsCompletedIfReady($run, $actor);

            return $stop->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function reviseStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
        array $data,
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor, $data): DistributionStop {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Returned
                || $run->status !== OperationalReportStatus::RevisionRequired) {
                throw ValidationException::withMessages([
                    'status' => 'Data tujuan hanya dapat dikoreksi ketika laporan berstatus Perlu Revisi.',
                ]);
            }

            $collectionTask = ContainerCollectionTask::query()
                ->where('distribution_stop_id', $stop->getKey())
                ->lockForUpdate()
                ->first();
            if ($collectionTask && (int) $collectionTask->collected_containers > 0) {
                throw ValidationException::withMessages([
                    'stop' => 'Data tujuan tidak dapat diubah karena pengambilan ompreng sudah dimulai. Gunakan koreksi tercatat oleh Kepala Divisi.',
                ]);
            }

            $requestedStatus = DistributionStopStatus::tryFrom((string) ($data['status'] ?? ''));
            if (! $requestedStatus?->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => 'Status hasil pengantaran tidak valid.',
                ]);
            }

            $deliveredSmall = (int) ($data['delivered_small_portions'] ?? 0);
            $deliveredLarge = (int) ($data['delivered_large_portions'] ?? 0);
            $plannedSmall = (int) $stop->small_portions;
            $plannedLarge = (int) $stop->large_portions;
            $deliveredTotal = $deliveredSmall + $deliveredLarge;
            $plannedTotal = $plannedSmall + $plannedLarge;
            $failureReason = trim((string) ($data['failure_reason'] ?? ''));
            $recipientName = trim((string) ($data['recipient_name'] ?? ''));
            $photoPath = $data['handover_photo_path'] ?? $stop->handover_photo_path;
            $containersSent = (int) ($data['containers_sent'] ?? $stop->containers_sent);

            if ($deliveredSmall < 0 || $deliveredSmall > $plannedSmall
                || $deliveredLarge < 0 || $deliveredLarge > $plannedLarge) {
                throw ValidationException::withMessages([
                    'stop' => 'Jumlah porsi yang diserahkan tidak boleh melebihi porsi rencana tujuan.',
                ]);
            }

            if ($requestedStatus === DistributionStopStatus::Delivered
                && ($deliveredSmall !== $plannedSmall || $deliveredLarge !== $plannedLarge)) {
                throw ValidationException::withMessages([
                    'stop' => 'Status Selesai hanya dapat dipilih jika seluruh porsi diserahkan.',
                ]);
            }

            if ($requestedStatus === DistributionStopStatus::Partial
                && ($deliveredTotal <= 0 || $deliveredTotal >= $plannedTotal || $failureReason === '')) {
                throw ValidationException::withMessages([
                    'stop' => 'Status Terkirim Sebagian memerlukan jumlah di bawah rencana dan alasan selisih.',
                ]);
            }

            if ($requestedStatus === DistributionStopStatus::Failed) {
                $deliveredSmall = 0;
                $deliveredLarge = 0;

                if ($failureReason === '') {
                    throw ValidationException::withMessages([
                        'stop' => 'Alasan gagal dikirim wajib diisi.',
                    ]);
                }
            } elseif ($recipientName === '' || blank($photoPath)) {
                throw ValidationException::withMessages([
                    'stop' => 'Nama penerima dan foto dokumentasi wajib tersedia.',
                ]);
            }

            if ($requestedStatus !== DistributionStopStatus::Failed && $containersSent <= 0) {
                throw ValidationException::withMessages([
                    'containers_sent' => 'Jumlah ompreng atau wadah yang dikirim wajib lebih dari nol.',
                ]);
            }

            if ($requestedStatus === DistributionStopStatus::Failed) {
                $containersSent = 0;
            }

            $stop->update([
                'arrived_at' => $stop->arrived_at ?: now(),
                'delivered_small_portions' => $deliveredSmall,
                'delivered_large_portions' => $deliveredLarge,
                'returned_small_portions' => $plannedSmall - $deliveredSmall,
                'returned_large_portions' => $plannedLarge - $deliveredLarge,
                'recipient_name' => $requestedStatus === DistributionStopStatus::Failed
                    ? ($recipientName ?: null)
                    : $recipientName,
                'recipient_position' => trim((string) ($data['recipient_position'] ?? '')) ?: null,
                'handover_photo_path' => $photoPath,
                'containers_sent' => $containersSent,
                'failure_reason' => $requestedStatus === DistributionStopStatus::Delivered
                    ? null
                    : $failureReason,
                'status' => $requestedStatus,
            ]);

            $run->recalculateTotals();
            $this->writeHistory(
                $run,
                $actor,
                'destination_revision_saved',
                $run->state->value,
                $run->state->value,
                $stop->destination_name,
            );
            app(ContainerCollectionWorkflow::class)->syncTaskFromStop($stop->refresh());

            return $stop->refresh();
        });
    }

    public function finish(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        $returnedRun = DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            $run = $this->lockedRun($run);
            $this->assertAssignedActor($run, $actor);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::DestinationsCompleted) {
                throw ValidationException::withMessages([
                    'state' => 'Seluruh tujuan harus selesai sebelum driver kembali ke SPPG.',
                ]);
            }

            $run->recalculateTotals();
            $run = $this->lockedRun($run);
            $this->validateStopsForCompletion($run);

            $previousState = $run->state->value;
            $run->update([
                'returned_at' => $data['returned_at'] ?? now(),
                'state' => DistributionRunState::Returned,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $run,
                $actor,
                'returned',
                $previousState,
                $run->state->value,
            );

            return $run->refresh();
        });

        $this->completeFieldPlanIfReady($returnedRun, $actor);

        return $returnedRun->refresh();
    }

    public function submit(DistributionRun $run, User $actor, ?string $notes = null): DistributionRun
    {
        abort_unless($actor->can('distribution.submit'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $runs = $this->lockedReportGroup($run);

            $messages = [];
            foreach ($runs as $routeRun) {
                if (! $routeRun->isReportEditable()) {
                    $messages[] = "{$routeRun->route_name}: laporan tidak dapat diajukan pada status saat ini.";

                    continue;
                }

                foreach ($this->submissionIssues($routeRun, false) as $issue) {
                    $messages[] = "{$routeRun->route_name}: {$issue}";
                }
            }

            if ($messages !== []) {
                throw ValidationException::withMessages([
                    'readiness' => implode(' ', array_unique($messages)),
                ]);
            }

            foreach ($runs as $routeRun) {
                $previousStatus = $routeRun->status->value;
                $routeRun->update([
                    'status' => OperationalReportStatus::Submitted,
                    'submitted_by' => $actor->getKey(),
                    'submitted_at' => now(),
                    'verified_by' => null,
                    'verified_at' => null,
                    'review_notes' => null,
                    'updated_by' => $actor->getKey(),
                ]);

                $this->writeHistory(
                    $routeRun,
                    $actor,
                    'daily_report_submitted',
                    $routeRun->state->value,
                    $routeRun->state->value,
                    $notes,
                    $previousStatus,
                    $routeRun->status->value,
                );
            }

            return $runs->firstWhere('id', $run->getKey())?->refresh()
                ?? $run->refresh();
        });
    }

    public function verify(DistributionRun $run, User $actor, ?string $notes = null): DistributionRun
    {
        abort_unless($actor->can('distribution.approve'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $runs = $this->lockedReportGroup($run);
            $approvalService = app(OperationalReportApprovalService::class);
            $currentStatus = $run->fresh()->status;

            foreach ($runs as $routeRun) {
                if ($routeRun->status !== $currentStatus) {
                    throw ValidationException::withMessages([
                        'status' => 'Status laporan seluruh rute belum seragam.',
                    ]);
                }

                $approvalService->assertCanReviewStage($routeRun->status, $actor);
            }

            $nextStatus = $approvalService->nextApprovedStatus($currentStatus, $actor);
            $action = $approvalService->reviewActionName($nextStatus);

            foreach ($runs as $routeRun) {
                $previousStatus = $routeRun->status->value;
                $routeRun->update([
                    'status' => $nextStatus,
                    'verified_by' => $actor->getKey(),
                    'verified_at' => now(),
                    'review_notes' => $notes,
                    'updated_by' => $actor->getKey(),
                ]);

                $this->writeHistory(
                    $routeRun,
                    $actor,
                    $action,
                    $routeRun->state->value,
                    $routeRun->state->value,
                    $notes,
                    $previousStatus,
                    $nextStatus->value,
                );
            }

            return $runs->firstWhere('id', $run->getKey())?->refresh()
                ?? $run->refresh();
        });
    }

    public function requestRevision(DistributionRun $run, User $actor, string $notes): DistributionRun
    {
        abort_unless($actor->can('distribution.approve'), 403);

        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan revisi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $runs = $this->lockedReportGroup($run);
            $approvalService = app(OperationalReportApprovalService::class);
            $currentStatus = $run->fresh()->status;

            foreach ($runs as $routeRun) {
                if ($routeRun->status !== $currentStatus) {
                    throw ValidationException::withMessages([
                        'status' => 'Status laporan seluruh rute belum seragam.',
                    ]);
                }

                $approvalService->assertCanReviewStage($routeRun->status, $actor);
            }

            foreach ($runs as $routeRun) {
                $previousStatus = $routeRun->status->value;
                $routeRun->update([
                    'status' => OperationalReportStatus::RevisionRequired,
                    'verified_by' => null,
                    'verified_at' => null,
                    'review_notes' => $notes,
                    'updated_by' => $actor->getKey(),
                ]);

                $this->writeHistory(
                    $routeRun,
                    $actor,
                    'revision_requested',
                    $routeRun->state->value,
                    $routeRun->state->value,
                    $notes,
                    $previousStatus,
                    OperationalReportStatus::RevisionRequired->value,
                );
            }

            return $runs->firstWhere('id', $run->getKey())?->refresh()
                ?? $run->refresh();
        });
    }

    public function submissionIssues(DistributionRun $run, bool $includeGroup = true): array
    {
        $run = DistributionRun::query()
            ->with(['stops', 'incidents'])
            ->findOrFail($run->getKey());

        $run->recalculateTotals();
        $run->refresh()->load(['stops', 'incidents']);

        $issues = [];

        if ($run->state !== DistributionRunState::Returned) {
            $issues[] = 'Driver belum kembali ke SPPG.';
        }

        if ($includeGroup && ! $run->allRoutesReturned()) {
            $issues[] = 'Masih ada rute lain pada rencana distribusi ini yang belum kembali ke SPPG.';
        }

        if ($run->stops->isEmpty()) {
            $issues[] = 'Minimal satu tujuan distribusi wajib tersedia.';
        }

        if ((int) $run->loaded_small_portions !== (int) $run->planned_small_portions
            || (int) $run->loaded_large_portions !== (int) $run->planned_large_portions) {
            $issues[] = 'Jumlah muatan tidak sama dengan total rencana porsi tujuan.';
        }

        if ($run->unaccounted_total !== 0) {
            $issues[] = 'Masih ada porsi yang belum tercatat sebagai diserahkan atau tidak tersalurkan.';
        }

        foreach ($run->stops as $stop) {
            foreach ($this->stopIssues($stop) as $issue) {
                $issues[] = "{$stop->destination_name}: {$issue}";
            }
        }

        $openSeriousIncidents = $run->incidents->filter(function ($incident): bool {
            return in_array($incident->severity, [
                DistributionIncidentSeverity::High,
                DistributionIncidentSeverity::Critical,
            ], true) && $incident->status !== DistributionIncidentStatus::Resolved;
        });

        if ($openSeriousIncidents->isNotEmpty()) {
            $issues[] = 'Insiden tingkat tinggi atau kritis harus diselesaikan terlebih dahulu.';
        }

        return array_values(array_unique($issues));
    }

    private function markDestinationsCompletedIfReady(DistributionRun $run, User $actor): void
    {
        $run = $this->lockedRun($run);

        if ($run->state !== DistributionRunState::Departed || ! $run->stops()->exists()) {
            return;
        }

        $hasOpenStop = $run->stops()
            ->whereNotIn('status', [
                DistributionStopStatus::Delivered->value,
                DistributionStopStatus::Partial->value,
                DistributionStopStatus::Failed->value,
            ])
            ->exists();

        if ($hasOpenStop) {
            return;
        }

        $previousState = $run->state->value;
        $run->update([
            'state' => DistributionRunState::DestinationsCompleted,
            'destinations_completed_at' => now(),
            'updated_by' => $actor->getKey(),
        ]);

        $this->writeHistory(
            $run,
            $actor,
            'all_destinations_completed',
            $previousState,
            $run->state->value,
        );
    }

    private function validateStopsForCompletion(DistributionRun $run): void
    {
        $run->load('stops');
        $messages = [];

        foreach ($run->stops as $stop) {
            foreach ($this->stopIssues($stop) as $issue) {
                $messages[] = "{$stop->destination_name}: {$issue}";
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages([
                'stops' => implode(' ', $messages),
            ]);
        }

        if ($run->unaccounted_total !== 0) {
            throw ValidationException::withMessages([
                'totals' => 'Jumlah porsi diserahkan dan tidak tersalurkan harus sama dengan jumlah muatan.',
            ]);
        }
    }

    private function stopIssues(DistributionStop $stop): array
    {
        $issues = [];

        if (! $stop->status?->isTerminal()) {
            $issues[] = 'status tujuan belum selesai.';

            return $issues;
        }

        $smallAccounted = (int) $stop->delivered_small_portions + (int) $stop->returned_small_portions;
        $largeAccounted = (int) $stop->delivered_large_portions + (int) $stop->returned_large_portions;

        if ($smallAccounted !== (int) $stop->small_portions
            || $largeAccounted !== (int) $stop->large_portions) {
            $issues[] = 'jumlah diserahkan dan tidak tersalurkan belum sama dengan porsi rencana.';
        }

        if (in_array($stop->status, [DistributionStopStatus::Delivered, DistributionStopStatus::Partial], true)) {
            if (! $stop->arrived_at) {
                $issues[] = 'waktu serah-terima belum tercatat.';
            }

            if (blank($stop->recipient_name)) {
                $issues[] = 'nama penerima belum diisi.';
            }

            if (blank($stop->handover_photo_path)) {
                $issues[] = 'foto dokumentasi serah-terima belum tersedia.';
            }

            if ((int) $stop->containers_sent <= 0) {
                $issues[] = 'jumlah ompreng atau wadah yang dikirim belum diisi.';
            }
        }

        if ($stop->status === DistributionStopStatus::Partial && blank($stop->failure_reason)) {
            $issues[] = 'alasan pengiriman sebagian wajib diisi.';
        }

        if ($stop->status === DistributionStopStatus::Failed && blank($stop->failure_reason)) {
            $issues[] = 'alasan gagal dikirim wajib diisi.';
        }

        return $issues;
    }

    private function assertAssignedActor(DistributionRun $run, User $actor): void
    {
        if ($actor->can('distribution.approve')) {
            return;
        }

        abort_unless((int) $run->petugas_id === (int) $actor->getKey(), 403);
    }

    /** @return array<int, string> */
    private function activeDriverStates(): array
    {
        return [
            DistributionRunState::Assigned->value,
            DistributionRunState::Loaded->value,
            DistributionRunState::Departed->value,
            DistributionRunState::DestinationsCompleted->value,
        ];
    }

    /** @return Collection<int, DistributionRun> */
    private function lockedReportGroup(DistributionRun $run): Collection
    {
        $query = DistributionRun::query()
            ->where('sppg_unit_id', $run->sppg_unit_id);

        if ($run->field_distribution_plan_id) {
            $query->where('field_distribution_plan_id', $run->field_distribution_plan_id);
        } else {
            $query->whereKey($run->getKey());
        }

        return $query
            ->where('state', '!=', DistributionRunState::Cancelled->value)
            ->orderBy('route_name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function completeFieldPlanIfReady(DistributionRun $run, User $actor): void
    {
        $plan = $run->fieldDistributionPlan()->first();

        if (! $plan || $plan->status !== FieldDistributionPlanStatus::Activated) {
            return;
        }

        $hasOpenRoute = $plan->distributionRuns()
            ->whereNotIn('state', [
                DistributionRunState::Returned->value,
                DistributionRunState::Cancelled->value,
            ])
            ->exists();

        if ($hasOpenRoute) {
            return;
        }

        app(FieldDistributionPlanWorkflow::class)->complete(
            $plan,
            $actor,
            'Seluruh rute distribusi makanan telah kembali ke SPPG.',
        );
    }

    private function lockedRun(DistributionRun $run): DistributionRun
    {
        return DistributionRun::query()
            ->whereKey($run->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedStop(DistributionRun $run, DistributionStop $stop): DistributionStop
    {
        return $run->stops()
            ->whereKey($stop->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function writeHistory(
        DistributionRun $run,
        User $actor,
        string $action,
        ?string $previousState,
        ?string $newState,
        ?string $notes = null,
        ?string $previousStatus = null,
        ?string $newStatus = null,
    ): void {
        $run->histories()->create([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'snapshot' => [
                'route_name' => $run->route_name,
                'driver_name' => $run->driver_name,
                'kernet_name' => $run->kernet_name,
                'vehicle_name' => $run->vehicle_name,
                'vehicle_plate' => $run->vehicle_plate,
                'planned_small_portions' => $run->planned_small_portions,
                'planned_large_portions' => $run->planned_large_portions,
                'loaded_small_portions' => $run->loaded_small_portions,
                'loaded_large_portions' => $run->loaded_large_portions,
                'delivered_small_portions' => $run->delivered_small_portions,
                'delivered_large_portions' => $run->delivered_large_portions,
                'returned_small_portions' => $run->returned_small_portions,
                'returned_large_portions' => $run->returned_large_portions,
                'containers_returned' => $run->containers_returned,
                'containers_damaged' => $run->containers_damaged,
                'containers_lost' => $run->containers_lost,
            ],
        ]);
    }
}
