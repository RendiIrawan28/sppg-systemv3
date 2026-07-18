<?php

namespace App\Http\Controllers;

use App\Models\DistributionRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DistributionRunPdfController extends Controller
{
    public function __invoke(Request $request, DistributionRun $distributionRun): Response
    {
        $this->authorizeSystemRecord($distributionRun, 'distribution.export');

        $distributionRun->load([
            'sppgUnit',
            'portioningSession',
            'stops',
            'documentations',
            'incidents.stop',
            'petugas',
            'verifier',
        ]);

        $pdf = Pdf::loadView('reports.distribution-run-pdf', [
            'run' => $distributionRun,
        ])->setPaper('a4', 'landscape');

        return $pdf->download(
            str_replace('/', '-', $distributionRun->run_number) . '.pdf'
        );
    }
}
