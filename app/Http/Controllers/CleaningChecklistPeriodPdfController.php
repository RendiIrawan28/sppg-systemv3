<?php

namespace App\Http\Controllers;

use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Support\CleaningChecklistTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CleaningChecklistPeriodPdfController extends Controller
{
    public function __invoke(Request $request, CleaningArea $cleaningArea): Response
    {
        $this->authorizeSystemRecord($cleaningArea, 'cleaning.export');
        abort_unless(CleaningChecklistTemplate::supportsPeriodExport($cleaningArea), 422, 'Area ini belum memiliki template checklist periode.');

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();
        abort_if($start->diffInDays($end) > 31, 422, 'Rentang ekspor maksimal 31 hari.');

        $days = CleaningChecklistTemplate::workdays($start, $end, 10);
        $sessions = CleaningSession::query()
            ->with(['checklistItems', 'petugas'])
            ->where('sppg_unit_id', $cleaningArea->sppg_unit_id)
            ->where('cleaning_area_id', $cleaningArea->getKey())
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (CleaningSession $session): string => $session->scheduled_date->toDateString());

        $items = CleaningChecklistTemplate::items(CleaningChecklistTemplate::forArea($cleaningArea));
        $pdf = Pdf::loadView('reports.cleaning-checklist-period-pdf', [
            'unit' => $cleaningArea->sppgUnit,
            'area' => $cleaningArea,
            'days' => $days,
            'sessions' => $sessions,
            'items' => $items,
            'periodLabel' => $start->translatedFormat('d F').' - '.$end->translatedFormat('d F Y'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download(sprintf(
            'Checklist-%s-%s-%s.pdf',
            str($cleaningArea->name)->slug(),
            $start->format('Ymd'),
            $end->format('Ymd'),
        ));
    }
}
