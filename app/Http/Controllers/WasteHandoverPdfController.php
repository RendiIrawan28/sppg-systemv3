<?php

namespace App\Http\Controllers;

use App\Models\WasteHandoverReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WasteHandoverPdfController extends Controller
{
    public function __invoke(Request $request, WasteHandoverReport $wasteHandoverReport): Response
    {
        abort_unless($request->user()?->can($wasteHandoverReport->division_type->permissionPrefix().'.view'), 403);
        abort_unless((int) $wasteHandoverReport->sppg_unit_id === (int) $request->attributes->get('v3Unit')?->getKey(), 404);
        $wasteHandoverReport->load(['sppgUnit', 'items', 'petugas', 'divisionApprover', 'verifier']);

        $pdf = Pdf::loadView('reports.waste-handover-pdf', [
            'report' => $wasteHandoverReport,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(str_replace('/', '-', $wasteHandoverReport->report_number).'.pdf');
    }
}
