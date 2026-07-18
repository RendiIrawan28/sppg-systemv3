<?php

namespace App\Http\Controllers;

use App\Models\PortioningSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortioningSessionPdfController extends Controller
{
    public function __invoke(Request $request, PortioningSession $portioningSession): Response
    {
        $this->authorizeSystemRecord($portioningSession, 'portioning.export');

        $portioningSession->load([
            'sppgUnit',
            'processingBatch',
            'routeAllocations',
            'weightSamples',
            'leftoverRecords',
            'documentations',
            'deviations',
            'handover',
            'petugas',
            'verifier',
        ]);

        $pdf = Pdf::loadView('reports.portioning-session-pdf', [
            'session' => $portioningSession,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(
            str_replace('/', '-', $portioningSession->session_number) . '.pdf'
        );
    }
}
