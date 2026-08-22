<?php

namespace App\Console\Commands;

use App\Models\MenuCycleDay;
use App\Services\MenuServiceCalendarService;
use App\Services\ServiceHolidayImpactService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileServiceHolidays extends Command
{
    protected $signature = 'service-holidays:reconcile {--from=} {--to=}';

    protected $description = 'Menyelaraskan akhir pekan dan master hari libur dengan siklus serta dokumen turunannya.';

    public function handle(MenuServiceCalendarService $calendar, ServiceHolidayImpactService $impact): int
    {
        $from = Carbon::parse($this->option('from') ?: MenuCycleDay::query()->min('service_date') ?: today())->startOfDay();
        $to = Carbon::parse($this->option('to') ?: MenuCycleDay::query()->max('service_date') ?: today()->addYear())->startOfDay();

        if ($from->gt($to)) {
            $this->error('Tanggal --from tidak boleh setelah --to.');

            return self::FAILURE;
        }

        $units = MenuCycleDay::query()
            ->with('cycle:id,sppg_unit_id')
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (MenuCycleDay $day): ?int => $day->cycle?->sppg_unit_id)
            ->filter()
            ->unique()
            ->values();

        $datesProcessed = 0;
        foreach ($units as $unitId) {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $holiday = $calendar->holidayFor((int) $unitId, $cursor);
                if ($holiday) {
                    $impact->reconcileDate((int) $unitId, $cursor, null, $holiday->name);
                    $datesProcessed++;
                }
                $cursor->addDay();
            }
        }

        $this->info("Rekonsiliasi selesai untuk {$datesProcessed} tanggal libur.");

        return self::SUCCESS;
    }
}
