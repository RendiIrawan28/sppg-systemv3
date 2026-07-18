<?php

namespace App\Http\Controllers;

use App\Enums\WasteDivision;
use App\Models\WasteHandoverReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreparationWasteHandoverPdfController extends Controller
{
    public function __invoke(Request $request, WasteHandoverReport $report): Response
    {
        abort_unless($report->division_type === WasteDivision::Preparation, 404);
        $this->authorizeSystemRecord($report, 'preparation.export');

        $report->load([
            'sppgUnit',
            'items',
            'petugas',
            'submitter',
            'verifier',
        ]);

        return Pdf::loadView(
            'reports.preparation-waste-handover-pdf',
            ['report' => $report]
        )
            ->setPaper('a4')
            ->download('BA-Limbah-' . str_replace('/', '-', $report->report_number) . '.pdf');
    }
}
