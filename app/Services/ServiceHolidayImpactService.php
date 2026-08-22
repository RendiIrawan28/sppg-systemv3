<?php

namespace App\Services;

use App\Enums\MenuDayRevisionStatus;
use App\Enums\NutritionRecordStatus;
use App\Models\MenuCycleDay;
use App\Models\MenuDayRevisionRequest;
use App\Models\NutritionRequirementPlan;
use App\Models\ProcurementRequest;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class ServiceHolidayImpactService
{
    public function reconcileDate(
        int $unitId,
        string|DateTimeInterface $date,
        ?int $actorId = null,
        ?string $holidayName = null,
    ): array {
        $date = CarbonImmutable::parse($date)->toDateString();
        $holidayName ??= app(MenuServiceCalendarService::class)->holidayFor($unitId, $date)?->name
            ?? 'Libur Pelayanan';

        return DB::transaction(function () use ($unitId, $date, $actorId, $holidayName): array {
            $detached = 0;
            $revisionRequests = 0;

            $days = MenuCycleDay::query()
                ->with(['cycle', 'menu'])
                ->whereDate('service_date', $date)
                ->whereHas('cycle', fn ($query) => $query->where('sppg_unit_id', $unitId))
                ->lockForUpdate()
                ->get();

            foreach ($days as $day) {
                if (! $day->menu_id) {
                    continue;
                }

                if ($day->cycle?->isEditable()) {
                    $day->update([
                        'menu_id' => null,
                        'source_menu_id' => null,
                        'snapshot_version' => 0,
                        'snapshot_created_at' => null,
                        'revision_notes' => "Menu dilepas otomatis karena {$holidayName}.",
                    ]);
                    $detached++;

                    continue;
                }

                $requesterId = $actorId ?: $day->cycle?->created_by;
                if ($requesterId && ! $day->hasOpenRevisionRequest()) {
                    MenuDayRevisionRequest::query()->create([
                        'sppg_unit_id' => $unitId,
                        'menu_cycle_id' => $day->menu_cycle_id,
                        'menu_cycle_day_id' => $day->getKey(),
                        'original_menu_id' => $day->menu_id,
                        'status' => MenuDayRevisionStatus::PendingAuthorization,
                        'reason' => "Tanggal ditetapkan sebagai {$holidayName}.",
                        'impact_notes' => 'Persetujuan hanya akan melepas menu dari hari libur; master menu dan hari lain tidak berubah.',
                        'snapshot' => [
                            'holiday_detach' => true,
                            'holiday_name' => $holidayName,
                            'service_date' => $date,
                            'menu_id' => $day->menu_id,
                        ],
                        'requested_by' => $requesterId,
                        'requested_at' => now(),
                    ]);
                    $day->update([
                        'revision_status' => MenuDayRevisionStatus::PendingAuthorization->value,
                        'revision_notes' => "Menunggu persetujuan pelepasan menu karena {$holidayName}.",
                        'revision_submitted_at' => now(),
                    ]);
                    $revisionRequests++;
                }
            }

            $requirements = NutritionRequirementPlan::query()
                ->where('sppg_unit_id', $unitId)
                ->whereDate('requirement_date', $date)
                ->where('status', '!=', NutritionRecordStatus::Cancelled->value)
                ->whereDoesntHave('procurementRequest', fn ($query) => $query->where('status', ProcurementRequest::STATUS_ORDERED))
                ->update([
                    'status' => NutritionRecordStatus::Cancelled->value,
                    'revision_notes' => "Dibatalkan otomatis karena {$holidayName}.",
                ]);

            $procurements = ProcurementRequest::query()
                ->where('sppg_unit_id', $unitId)
                ->whereDate('needed_date', $date)
                ->where('status', '!=', ProcurementRequest::STATUS_ORDERED)
                ->where('status', '!=', ProcurementRequest::STATUS_CANCELLED)
                ->update([
                    'status' => ProcurementRequest::STATUS_CANCELLED,
                    'price_status' => 'cancelled',
                    'finance_notes' => "Dibatalkan otomatis karena {$holidayName}.",
                ]);

            return compact('detached', 'revisionRequests', 'requirements', 'procurements');
        });
    }
}
