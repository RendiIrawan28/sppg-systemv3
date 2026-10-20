<?php

namespace App\Services;

use App\Enums\MenuComponentType;
use App\Models\Menu;
use App\Models\DistributionRun;
use App\Models\FieldDailyReport;
use App\Models\FieldWeeklyReportPhoto;
use App\Models\MenuCycleDay;
use App\Models\SppgUnit;
use Carbon\CarbonImmutable;

class FieldWeeklyReportBuilder
{
    public function __construct(private readonly FieldWeeklyReportImage $images) {}

    /**
     * Read-only snapshot for a Monday-Friday week; never regenerates daily reports.
     * All data sources are explicitly scoped to the authorized SPPG unit.
     *
     * @return array<string, mixed>
     */
    public function build(SppgUnit $unit, CarbonImmutable $monday, array $options = []): array
    {
        $monday = $monday->startOfWeek();
        $friday = $monday->addDays(4);
        $start = $monday->toDateString();
        $end = $friday->toDateString();

        $reports = FieldDailyReport::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereBetween('report_date', [$start, $end])
            ->with(['incidents', 'approver'])
            ->get()->keyBy(fn ($report) => $report->report_date->toDateString());

        $menus = MenuCycleDay::query()
            ->whereBetween('service_date', [$start, $end])
            ->whereHas('cycle', fn ($q) => $q->where('sppg_unit_id', $unit->getKey())
                ->whereIn('status', ['approved', 'active', 'archived']))
            ->with(['menu.items', 'variants.menu.items'])
            ->orderByDesc('id')->get()
            ->unique(fn ($item) => $item->service_date->toDateString())
            ->keyBy(fn ($item) => $item->service_date->toDateString());

        $runs = DistributionRun::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereBetween('distribution_date', [$start, $end])
            ->with(['stops', 'documentations'])
            ->orderBy('distribution_date')->orderBy('id')->get()
            ->groupBy(fn ($run) => $run->distribution_date->toDateString());

        $manualPhotos = FieldWeeklyReportPhoto::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereBetween('photo_date', [$start, $end])
            ->orderBy('id')->get()
            ->groupBy(fn ($photo) => $photo->photo_date->toDateString());

        $includeMenu = (bool) ($options['menu_photos'] ?? true);
        $includeActivities = (bool) ($options['activity_photos'] ?? true);
        $includeWaybills = (bool) ($options['waybills'] ?? true);
        $days = [];
        for ($i = 0; $i < 5; $i++) {
            $date = $monday->addDays($i);
            $key = $date->toDateString();
            $report = $reports->get($key);
            $dayMenu = $menus->get($key);
            $dayRuns = $runs->get($key) ?? collect();
            $photos = $manualPhotos->get($key) ?? collect();
            $school = $dayMenu?->menu;
            $threeB = $dayMenu?->effectiveMenuForAudience(MenuAudienceMenuResolver::POSYANDU_3B);

            $day = [
                'date' => $date,
                'report' => $report,
                'school_menu' => $this->menuRows($school),
                'three_b_menu' => $this->menuRows($threeB),
                'school_menu_name' => $school?->name,
                'three_b_menu_name' => $threeB?->name,
                'runs' => $dayRuns,
                'activity_photos' => [],
                'school_menu_photos' => [],
                'three_b_menu_photos' => [],
            ];

            if ($includeMenu) {
                $day['school_menu_photos'] = $this->photoRows($photos->where('photo_type', 'menu_school')->take(2));
                $day['three_b_menu_photos'] = $this->photoRows($photos->where('photo_type', 'menu_3b')->take(2));
            }

            if ($includeActivities) {
                $candidates = [];
                foreach ($photos->where('photo_type', 'activity') as $photo) {
                    $candidates[] = ['path' => $photo->photo_path, 'caption' => $photo->caption, 'disk' => 'local'];
                }
                foreach ($dayRuns as $run) {
                    foreach ($run->documentations as $doc) {
                        $candidates[] = ['path' => $doc->photo_path, 'caption' => $doc->caption ?: $run->route_name, 'disk' => 'public'];
                    }
                    foreach ($run->stops as $stop) {
                        if (filled($stop->handover_photo_path)) {
                            $candidates[] = [
                                'path' => $stop->handover_photo_path,
                                'caption' => 'Serah-terima: '.$stop->destination_name,
                                'disk' => 'public',
                            ];
                        }
                    }
                }
                $seen = [];
                foreach ($candidates as $candidate) {
                    $index = $candidate['disk'].':'.$candidate['path'];
                    if (isset($seen[$index])) {
                        continue;
                    }
                    $seen[$index] = true;
                    $uri = $this->images->dataUri($candidate['path'], $candidate['disk']);
                    if ($uri) {
                        $day['activity_photos'][] = ['uri' => $uri, 'caption' => $candidate['caption']];
                    }
                    if (count($day['activity_photos']) >= 4) {
                        break;
                    }
                }
            }
            $days[] = $day;
        }

        return [
            'unit' => $unit,
            'start' => $monday,
            'end' => $friday,
            'days' => $days,
            'daily_count' => $reports->count(),
            'total_delivered' => $reports->sum('delivered_portions'),
            'total_returned' => $reports->sum('returned_portions'),
            'total_destinations' => $reports->sum('completed_destinations'),
            'includes' => [
                'menu_photos' => $includeMenu,
                'activity_photos' => $includeActivities,
                'waybills' => $includeWaybills,
            ],
            'fully_approved' => $reports->count() === 5 && $reports->every(
                fn ($r) => $r->status?->value === 'approved',
            ),
        ];
    }

    /** Same item/component order as the existing siklus-menu PDF. */
    private function menuRows(?Menu $menu): array
    {
        if (! $menu) {
            return [];
        }
        $order = [
            MenuComponentType::Staple->value,
            MenuComponentType::AnimalProtein->value,
            MenuComponentType::PlantProtein->value,
            MenuComponentType::Vegetable->value,
            MenuComponentType::Fruit->value,
            MenuComponentType::Milk->value,
        ];
        return collect($order)
            ->map(function (string $type) use ($menu): ?string {
                $names = $menu->items->where('item_type', $type)
                    ->sortBy('sort_order')->pluck('name')
                    ->map(fn ($v) => trim((string) $v))->filter()->unique()->values();
                return $names->isEmpty() ? null : $names->implode(' / ');
            })
            ->filter()->values()->all();
    }

    private function photoRows($photos): array
    {
        $result = [];
        foreach ($photos as $photo) {
            $uri = $this->images->dataUri($photo->photo_path, 'local');
            if ($uri) {
                $result[] = ['uri' => $uri, 'caption' => $photo->caption];
            }
        }
        return $result;
    }
}
