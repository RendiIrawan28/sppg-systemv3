<?php

namespace App\Http\Controllers;

use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Support\CleaningChecklistTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CleaningWarehouseChecklistPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->can('cleaning.export'), 403);
        $unit = $request->attributes->get('v3Unit');
        abort_unless($unit, 404);
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();
        abort_if($start->diffInDays($end) > 31, 422, 'Rentang ekspor maksimal 31 hari.');

        $days = CleaningChecklistTemplate::workdays($start, $end, 10);
        $areas = CleaningArea::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('template_type', CleaningChecklistTemplate::WAREHOUSE)
            ->where('is_active', true)
            ->orderByRaw("CASE code WHEN 'GUDANG-BASAH' THEN 1 WHEN 'GUDANG-KERING' THEN 2 WHEN 'GUDANG-DINGIN' THEN 3 ELSE 4 END")
            ->get();

        $datasets = $areas->map(function (CleaningArea $area) use ($start, $end): array {
            return [
                'area' => $area,
                'sessions' => CleaningSession::query()
                    ->with('checklistItems')
                    ->where('cleaning_area_id', $area->getKey())
                    ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                    ->get()
                    ->keyBy(fn (CleaningSession $session): string => $session->scheduled_date->toDateString()),
            ];
        });

        $pdf = Pdf::loadView('reports.cleaning-warehouse-checklists-pdf', [
            'unit' => $unit,
            'days' => $days,
            'datasets' => $datasets,
            'items' => CleaningChecklistTemplate::items(CleaningChecklistTemplate::WAREHOUSE),
            'periodLabel' => $start->translatedFormat('d F').' - '.$end->translatedFormat('d F Y'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('Checklist-Gudang-'.$start->format('Ymd').'-'.$end->format('Ymd').'.pdf');
    }
}
