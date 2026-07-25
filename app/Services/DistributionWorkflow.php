<?php

namespace App\Services;

use App\Enums\DistributionIncidentSeverity;
use App\Enums\DistributionIncidentStatus;
use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\OperationalReportStatus;
use App\Models\DistributionRun;
use App\Models\DistributionStop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionWorkflow
{
    public function prepareLoad(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            $run = $this->lockedRun($run);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Perjalanan distribusi tidak dapat disiapkan pada kondisi saat ini.',
                ]);
            }

            $run->recalculateTotals();
            $run = $this->lockedRun($run);

            if ($run->stops()->count() === 0) {
                throw ValidationException::withMessages([
                    'stops' => 'Minimal satu tujuan distribusi wajib tersedia.',
                ]);
            }

            if ($run->portioning_session_id && ! $run->portioning_handover_id) {
                throw ValidationException::withMessages([
                    'portioning_handover_id' => 'Muatan belum diserahkan secara resmi oleh Divisi Pemorsian.',
                ]);
            }

            $loadedSmall = (int) $run->planned_small_portions;
            $loadedLarge = (int) $run->planned_large_portions;

            if (blank($data['vehicle_name'] ?? $run->vehicle_name)
                || blank($data['vehicle_plate'] ?? $run->vehicle_plate)
                || blank($data['driver_name'] ?? $run->driver_name)
                || blank($data['kernet_name'] ?? $run->kernet_name)) {
                throw ValidationException::withMessages([
                    'vehicle_name' => 'Kendaraan, nomor polisi, nama driver, dan nama kernet wajib diisi.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'loaded_small_portions' => $loadedSmall,
                'loaded_large_portions' => $loadedLarge,
                'vehicle_name' => $data['vehicle_name'] ?? $run->vehicle_name,
                'vehicle_plate' => $data['vehicle_plate'] ?? $run->vehicle_plate,
                'driver_name' => $data['driver_name'] ?? $run->driver_name,
                'kernet_name' => $data['kernet_name'] ?? $run->kernet_name,
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'state' => DistributionRunState::Loaded,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($run, $actor, 'loaded', $previousState, $run->state->value);

            return $run->refresh();
        });
    }

    public function depart(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            $run = $this->lockedRun($run);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Loaded) {
                throw ValidationException::withMessages([
                    'state' => 'Muatan harus disiapkan sebelum kendaraan berangkat.',
                ]);
            }

            $previousState = $run->state->value;
            $run->update([
                'actual_departure_at' => $data['actual_departure_at'] ?? now(),
                'departure_temperature_celsius' => $data['departure_temperature_celsius'] ?? null,
                'state' => DistributionRunState::Departed,
                'updated_by' => $actor->getKey(),
            ]);

            $run->stops()->where('status', DistributionStopStatus::Planned->value)->update([
                'status' => DistributionStopStatus::InTransit->value,
            ]);

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
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Departed
                || $stop->status !== DistributionStopStatus::InTransit) {
                throw ValidationException::withMessages([
                    'stop' => 'Tujuan belum dapat ditandai tiba pada kondisi saat ini.',
                ]);
            }

            $stop->update([
                'arrived_at' => now(),
                'delivered_small_portions' => $stop->small_portions,
                'delivered_large_portions' => $stop->large_portions,
                'status' => DistributionStopStatus::Arrived,
            ]);
            $this->writeHistory($run, $actor, 'arrived_at_destination', $run->state->value, $run->state->value, $stop->destination_name);

            return $stop->refresh();
        });
    }

    public function completeStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor): DistributionStop {
            $run = $this->lockedRun($run);
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Departed
                || $stop->status !== DistributionStopStatus::Arrived) {
                throw ValidationException::withMessages([
                    'stop' => 'Tekan Tiba di Tujuan sebelum mencatat penyerahan.',
                ]);
            }
            if (blank($stop->recipient_name) || blank($stop->handover_photo_path)) {
                throw ValidationException::withMessages([
                    'stop' => 'Nama penerima dan foto serah-terima wajib diisi.',
                ]);
            }

            $deliveredSmall = (int) $stop->delivered_small_portions;
            $deliveredLarge = (int) $stop->delivered_large_portions;
            if ($deliveredSmall < 0 || $deliveredSmall > (int) $stop->small_portions
                || $deliveredLarge < 0 || $deliveredLarge > (int) $stop->large_portions) {
                throw ValidationException::withMessages([
                    'stop' => 'Jumlah porsi yang diserahkan tidak boleh melebihi porsi tujuan.',
                ]);
            }

            $returnedSmall = (int) $stop->small_portions - $deliveredSmall;
            $returnedLarge = (int) $stop->large_portions - $deliveredLarge;
            $stop->update([
                'returned_small_portions' => $returnedSmall,
                'returned_large_portions' => $returnedLarge,
                'status' => ($returnedSmall + $returnedLarge) > 0
                    ? DistributionStopStatus::Partial
                    : DistributionStopStatus::Delivered,
            ]);
            $run->recalculateTotals();
            $this->writeHistory($run, $actor, 'delivered_at_destination', $run->state->value, $run->state->value, $stop->destination_name);

            return $stop->refresh();
        });
    }

    public function failStop(
        DistributionRun $run,
        DistributionStop $stop,
        User $actor,
    ): DistributionStop {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $stop, $actor): DistributionStop {
            $run = $this->lockedRun($run);
            $stop = $this->lockedStop($run, $stop);

            if ($run->state !== DistributionRunState::Departed
                || $stop->status !== DistributionStopStatus::Arrived
                || blank($stop->failure_reason)) {
                throw ValidationException::withMessages([
                    'stop' => 'Alasan gagal diserahkan wajib diisi setelah tiba di tujuan.',
                ]);
            }

            $stop->update([
                'delivered_small_portions' => 0,
                'delivered_large_portions' => 0,
                'returned_small_portions' => $stop->small_portions,
                'returned_large_portions' => $stop->large_portions,
                'status' => DistributionStopStatus::Failed,
            ]);
            $run->recalculateTotals();
            $this->writeHistory($run, $actor, 'delivery_failed', $run->state->value, $run->state->value, $stop->destination_name.': '.$stop->failure_reason);

            return $stop->refresh();
        });
    }

    public function finish(DistributionRun $run, User $actor, array $data): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $data): DistributionRun {
            $run = $this->lockedRun($run);

            if (! $run->isReportEditable() || $run->state !== DistributionRunState::Departed) {
                throw ValidationException::withMessages([
                    'state' => 'Perjalanan belum berada dalam tahap distribusi.',
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
                $data['notes'] ?? null,
            );

            app(OperationalHandoverFlow::class)
                ->createWashingSessionFromDistribution($run->refresh(), $actor);

            return $run->refresh();
        });
    }

    public function submit(DistributionRun $run, User $actor, ?string $notes = null): DistributionRun
    {
        abort_unless($actor->can('distribution.update'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $run = $this->lockedRun($run);

            if (! $run->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Perjalanan belum dapat diajukan. Selesaikan rute dan kembali ke SPPG terlebih dahulu.',
                ]);
            }

            $issues = $this->submissionIssues($run);
            if ($issues !== []) {
                throw ValidationException::withMessages([
                    'readiness' => implode(' ', $issues),
                ]);
            }

            $previousStatus = $run->status->value;
            $run->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $run,
                $actor,
                'submitted',
                $run->state->value,
                $run->state->value,
                $notes,
                $previousStatus,
                $run->status->value,
            );

            return $run->refresh();
        });
    }

    public function verify(DistributionRun $run, User $actor, ?string $notes = null): DistributionRun
    {
        abort_unless($actor->can('distribution.approve'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $run = $this->lockedRun($run);

            if (! app(OperationalReportApprovalService::class)->isReviewable($run->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $previousStatus = $run->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($run->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);

            $run->update([
                'status' => $nextStatus,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $run,
                $actor,
                $action,
                $run->state->value,
                $run->state->value,
                $notes,
                $previousStatus,
                $nextStatus->value,
            );

            return $run->refresh();
        });
    }

    public function requestRevision(DistributionRun $run, User $actor, string $notes): DistributionRun
    {
        abort_unless($actor->can('distribution.approve'), 403);

        return DB::transaction(function () use ($run, $actor, $notes): DistributionRun {
            $run = $this->lockedRun($run);

            app(OperationalReportApprovalService::class)->assertCanReviewStage($run->status, $actor);

            $previousStatus = $run->status->value;
            $run->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $run,
                $actor,
                'revision_requested',
                $run->state->value,
                $run->state->value,
                $notes,
                $previousStatus,
                $run->status->value,
            );

            return $run->refresh();
        });
    }

    public function submissionIssues(DistributionRun $run): array
    {
        $run = DistributionRun::query()
            ->with(['stops', 'documentations', 'incidents'])
            ->findOrFail($run->getKey());

        $run->recalculateTotals();
        $run->refresh()->load(['stops', 'documentations', 'incidents']);

        $issues = [];

        if ($run->state !== DistributionRunState::Returned) {
            $issues[] = 'Perjalanan belum diselesaikan dan kendaraan belum kembali ke SPPG.';
        }

        if ($run->stops->isEmpty()) {
            $issues[] = 'Minimal satu tujuan distribusi wajib tersedia.';
        }

        if ((int) $run->loaded_small_portions !== (int) $run->planned_small_portions
            || (int) $run->loaded_large_portions !== (int) $run->planned_large_portions) {
            $issues[] = 'Jumlah muatan tidak sama dengan total rencana porsi tujuan.';
        }

        if ($run->unaccounted_total !== 0) {
            $issues[] = 'Masih ada porsi yang belum tercatat sebagai terkirim atau kembali.';
        }

        foreach ($run->stops as $stop) {
            foreach ($this->stopIssues($stop) as $issue) {
                $issues[] = "{$stop->destination_name}: {$issue}";
            }
        }

        if ($run->documentations->isEmpty()) {
            $issues[] = 'Minimal satu foto dokumentasi umum wajib tersedia.';
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
                'totals' => 'Jumlah porsi terkirim dan kembali harus sama dengan jumlah muatan.',
            ]);
        }
    }

    private function stopIssues($stop): array
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
            $issues[] = 'jumlah terkirim dan kembali belum sama dengan porsi yang dibawa.';
        }

        if (in_array($stop->status, [DistributionStopStatus::Delivered, DistributionStopStatus::Partial], true)) {
            if (! $stop->arrived_at) {
                $issues[] = 'waktu tiba belum diisi.';
            }

            if (blank($stop->recipient_name)) {
                $issues[] = 'nama penerima belum diisi.';
            }

            if (blank($stop->handover_photo_path)) {
                $issues[] = 'foto serah-terima belum tersedia.';
            }
        }

        if ($stop->status === DistributionStopStatus::Failed && blank($stop->failure_reason)) {
            $issues[] = 'alasan gagal kirim wajib diisi.';
        }

        $containersAccounted = (int) $stop->containers_returned
            + (int) $stop->containers_damaged
            + (int) $stop->containers_lost;

        if ((int) $stop->containers_sent > 0
            && $containersAccounted !== (int) $stop->containers_sent) {
            $issues[] = 'ompreng kembali, rusak, dan hilang belum sama dengan jumlah yang dikirim.';
        }

        return $issues;
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
                'planned_small_portions' => $run->planned_small_portions,
                'planned_large_portions' => $run->planned_large_portions,
                'loaded_small_portions' => $run->loaded_small_portions,
                'loaded_large_portions' => $run->loaded_large_portions,
                'delivered_small_portions' => $run->delivered_small_portions,
                'delivered_large_portions' => $run->delivered_large_portions,
                'returned_small_portions' => $run->returned_small_portions,
                'returned_large_portions' => $run->returned_large_portions,
            ],
        ]);
    }
}
