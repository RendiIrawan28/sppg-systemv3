<?php

namespace App\Services;

use App\Enums\CleaningSessionState;
use App\Enums\OperationalReportStatus;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\V3\OperationalRecordInitializer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CleaningScheduleService
{
    /** @return Collection<int, CleaningSession> */
    public function ensureForDate(SppgUnit|int $unit, string $date, User $actor): Collection
    {
        $unitId = $unit instanceof SppgUnit ? $unit->getKey() : $unit;
        $scheduledDate = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($unitId, $scheduledDate, $actor): Collection {
            $areas = CleaningArea::query()
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('auto_schedule')->orWhere('auto_schedule', true);
                })
                ->where(function ($query): void {
                    $query->whereNull('frequency')->orWhere('frequency', 'daily');
                })
                ->orderBy('name')
                ->get();

            return $areas->map(function (CleaningArea $area) use ($unitId, $scheduledDate, $actor): CleaningSession {
                $session = CleaningSession::query()->firstOrCreate(
                    [
                        'sppg_unit_id' => $unitId,
                        'cleaning_area_id' => $area->getKey(),
                        'scheduled_date' => $scheduledDate,
                    ],
                    [
                        'shift' => $this->shiftForTime($area->scheduled_time),
                        'scheduled_start_at' => $area->scheduled_time
                            ? Carbon::parse($scheduledDate.' '.$area->scheduled_time)
                            : null,
                        'state' => CleaningSessionState::Planned,
                        'status' => OperationalReportStatus::Draft,
                        'created_by' => $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                        'source_system' => 'cleaning_auto_schedule',
                    ],
                );

                app(OperationalRecordInitializer::class)->initialize($session, $actor);

                return $session->refresh();
            });
        });
    }

    private function shiftForTime(?string $time): string
    {
        if (! $time) {
            return 'morning';
        }

        $hour = (int) substr($time, 0, 2);

        return match (true) {
            $hour >= 18 => 'night',
            $hour >= 12 => 'afternoon',
            default => 'morning',
        };
    }
}
