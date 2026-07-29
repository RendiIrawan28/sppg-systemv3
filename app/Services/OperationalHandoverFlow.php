<?php

namespace App\Services;

use App\Enums\CleaningSessionState;
use App\Enums\DistributionRunState;
use App\Enums\WashingSessionState;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\ContainerCollectionRun;
use App\Models\DistributionRun;
use App\Models\User;
use App\Models\WashingSession;
use App\Services\V3\OperationalRecordInitializer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalHandoverFlow
{
    public function createWashingSessionFromDistribution(DistributionRun $run, User $actor): WashingSession
    {
        return DB::transaction(function () use ($run, $actor): WashingSession {
            $run = DistributionRun::query()
                ->lockForUpdate()
                ->with(['stops', 'washingSessions'])
                ->findOrFail($run->getKey());

            if ($run->state !== DistributionRunState::Returned) {
                throw ValidationException::withMessages([
                    'distribution_run_id' => 'Sesi pencucian hanya dapat dibuat setelah distribusi kembali ke SPPG.',
                ]);
            }

            $existingSession = $run->washingSessions()->first();
            if ($existingSession) {
                return $existingSession;
            }

            $expected = (int) $run->stops->sum('containers_sent');
            $returned = (int) $run->containers_returned;
            $damaged = (int) $run->containers_damaged;
            $lost = (int) $run->containers_lost;

            // Fallback untuk data lama sebelum jumlah ompreng dicatat pada tingkat rute.
            if (($returned + $damaged + $lost) <= 0) {
                $returned = (int) $run->stops->sum('containers_returned');
                $damaged = (int) $run->stops->sum('containers_damaged');
                $lost = (int) $run->stops->sum('containers_lost');
            }

            if ($expected <= 0) {
                $expected = (int) $run->loaded_total;
            }

            if ($returned <= 0 && $expected > 0) {
                $returned = max(0, $expected - $damaged - $lost);
            }

            $session = WashingSession::query()->create([
                'sppg_unit_id' => $run->sppg_unit_id,
                'distribution_run_id' => $run->getKey(),
                'washing_date' => Carbon::parse($run->returned_at ?? now())->toDateString(),
                'menu_name_snapshot' => $run->menu_name_snapshot,
                'expected_containers' => $expected,
                // Jumlah fisik tetap diisi oleh petugas Pencucian saat menerima ompreng.
                'received_containers' => 0,
                'damaged_containers' => 0,
                'missing_containers' => 0,
                'received_at' => null,
                'state' => WashingSessionState::Planned,
                'washing_area' => 'Area Pencucian Ompreng',
                'petugas_id' => null,
                'petugas_name_snapshot' => null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'notes' => sprintf(
                    'Dibuat otomatis dari perjalanan %s (%s). Laporan Distribusi: kembali %d, rusak %d, hilang %d.',
                    $run->run_number,
                    $run->route_name ?: 'Rute Utama',
                    $returned,
                    $damaged,
                    $lost,
                ),
            ]);

            app(OperationalRecordInitializer::class)->initialize($session, $actor);

            return $session->refresh();
        });
    }


    public function createWashingSessionFromCollection(ContainerCollectionRun $run, User $actor): WashingSession
    {
        return DB::transaction(function () use ($run, $actor): WashingSession {
            $run = ContainerCollectionRun::query()
                ->lockForUpdate()
                ->with(['items.task', 'washingSession'])
                ->findOrFail($run->getKey());

            if ($run->state !== ContainerCollectionRun::RETURNED) {
                throw ValidationException::withMessages([
                    'container_collection_run_id' => 'Sesi pencucian hanya dapat dibuat setelah pengambilan ompreng kembali ke SPPG.',
                ]);
            }

            if ($run->washingSession) {
                return $run->washingSession;
            }

            $collected = (int) $run->items->sum('collected_quantity');
            $target = $collected;

            if ($collected <= 0) {
                throw ValidationException::withMessages([
                    'container_collection_run_id' => 'Belum ada ompreng yang dibawa kembali pada kegiatan ini.',
                ]);
            }

            $session = WashingSession::query()->create([
                'sppg_unit_id' => $run->sppg_unit_id,
                'container_collection_run_id' => $run->getKey(),
                'distribution_run_id' => null,
                'washing_date' => Carbon::parse($run->returned_at ?? now())->toDateString(),
                'menu_name_snapshot' => 'Pengambilan ompreng '.$run->run_number,
                'distribution_expected_containers' => $target,
                'distribution_returned_containers' => $collected,
                'distribution_damaged_containers' => 0,
                'distribution_lost_containers' => 0,
                'expected_containers' => $collected,
                'received_containers' => 0,
                'damaged_containers' => 0,
                'missing_containers' => 0,
                'received_at' => null,
                'state' => WashingSessionState::Planned,
                'washing_area' => 'Area Pencucian Ompreng',
                'petugas_id' => null,
                'petugas_name_snapshot' => null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'notes' => sprintf(
                    'Dibuat otomatis dari pengambilan ompreng %s oleh %s. Total dibawa kembali: %d.',
                    $run->run_number,
                    $run->driver_name_snapshot,
                    $collected,
                ),
            ]);

            app(OperationalRecordInitializer::class)->initialize($session, $actor);

            return $session->refresh();
        });
    }

    public function createCleaningSessionsAfterWashing(WashingSession $washingSession, User $actor): int
    {
        return DB::transaction(function () use ($washingSession, $actor): int {
            $washingSession = WashingSession::query()
                ->lockForUpdate()
                ->findOrFail($washingSession->getKey());

            if (! in_array($washingSession->state, [WashingSessionState::Completed, WashingSessionState::Ready], true)) {
                throw ValidationException::withMessages([
                    'washing_session_id' => 'Sesi kebersihan akhir hanya dapat dibuat setelah pencucian selesai.',
                ]);
            }

            $areas = CleaningArea::query()
                ->where('sppg_unit_id', $washingSession->sppg_unit_id)
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereIn('category', ['washing', 'pencucian'])
                        ->orWhere('name', 'like', '%cuci%')
                        ->orWhere('name', 'like', '%pencucian%');
                })
                ->orderBy('name')
                ->get();

            if ($areas->isEmpty()) {
                throw ValidationException::withMessages([
                    'cleaning_area_id' => 'Belum ada master area kebersihan aktif. Tambahkan area kebersihan terlebih dahulu.',
                ]);
            }

            $date = Carbon::parse($washingSession->completed_at ?? $washingSession->washing_date ?? now())->toDateString();
            $created = 0;

            foreach ($areas as $area) {
                $exists = CleaningSession::query()
                    ->where('sppg_unit_id', $washingSession->sppg_unit_id)
                    ->where('cleaning_area_id', $area->getKey())
                    ->whereDate('scheduled_date', $date)
                    ->where('shift', 'evening')
                    ->exists();

                if ($exists) {
                    continue;
                }

                $session = CleaningSession::query()->create([
                    'sppg_unit_id' => $washingSession->sppg_unit_id,
                    'cleaning_area_id' => $area->getKey(),
                    'scheduled_date' => $date,
                    'shift' => 'evening',
                    'scheduled_start_at' => Carbon::parse($date.' 14:00:00'),
                    'state' => CleaningSessionState::Planned,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                    'notes' => sprintf('Sesi kebersihan akhir dibuat dari pencucian %s.', $washingSession->session_number),
                ]);
                app(OperationalRecordInitializer::class)->initialize($session, $actor);

                $created++;
            }

            return $created;
        });
    }
}
