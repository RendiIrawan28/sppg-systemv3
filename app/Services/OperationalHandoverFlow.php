<?php

namespace App\Services;

use App\Enums\CleaningSessionState;
use App\Enums\DistributionRunState;
use App\Enums\WashingSessionState;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
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

            if ($run->washingSessions()->exists()) {
                throw ValidationException::withMessages([
                    'distribution_run_id' => 'Sesi pencucian untuk perjalanan ini sudah dibuat.',
                ]);
            }

            $expected = (int) $run->stops->sum('containers_sent');
            $returned = (int) $run->stops->sum('containers_returned');
            $damaged = (int) $run->stops->sum('containers_damaged');
            $lost = (int) $run->stops->sum('containers_lost');

            if ($expected <= 0) {
                $expected = (int) $run->delivered_total;
            }

            if ($returned <= 0 && $expected > 0) {
                $returned = max(0, $expected - $damaged - $lost);
            }

            $received = $returned + $damaged;

            $session = WashingSession::query()->create([
                'sppg_unit_id' => $run->sppg_unit_id,
                'distribution_run_id' => $run->getKey(),
                'washing_date' => Carbon::parse($run->returned_at ?? now())->toDateString(),
                'menu_name_snapshot' => $run->menu_name_snapshot,
                'expected_containers' => $expected,
                'received_containers' => $received,
                'damaged_containers' => $damaged,
                'missing_containers' => $lost,
                'received_at' => $run->returned_at ?? now(),
                'state' => $received > 0 ? WashingSessionState::Received : WashingSessionState::Planned,
                'washing_area' => 'Area Pencucian Ompreng',
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'notes' => sprintf('Dibuat otomatis dari perjalanan distribusi %s.', $run->run_number),
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
                    $query->whereIn('category', ['washing', 'pencucian', 'kitchen', 'production', 'area'])
                        ->orWhere('name', 'like', '%cuci%')
                        ->orWhere('name', 'like', '%pencucian%')
                        ->orWhere('name', 'like', '%dapur%')
                        ->orWhere('name', 'like', '%produksi%');
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
