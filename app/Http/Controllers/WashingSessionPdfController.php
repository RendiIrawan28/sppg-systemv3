<?php

namespace App\Http\Controllers;

use App\Models\WashingSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WashingSessionPdfController extends Controller
{
    public function __invoke(Request $request, WashingSession $washingSession): Response
    {
        $this->authorizeSystemRecord($washingSession, 'washing.export');

        $washingSession->load([
            'sppgUnit', 'distributionRun', 'checklistItems.checker', 'measurements.measurer',
            'chemicalUsages', 'wasteRecords', 'documentations', 'deviations.resolver',
            'petugas', 'verifier',
        ]);

        $pdf = Pdf::loadView('reports.washing-session-pdf', ['session' => $washingSession])->setPaper('a4', 'portrait');
        return $pdf->download(str_replace('/', '-', $washingSession->session_number) . '.pdf');
    }
}
