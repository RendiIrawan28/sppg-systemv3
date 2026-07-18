<?php

namespace App\Http\Controllers;

use App\Models\ProcessingBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcessingBatchPdfController extends Controller
{
    public function __invoke(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeSystemRecord($processingBatch, 'processing.export');

        $processingBatch->load([
            'sppgUnit',
            'materialUsages',
            'temperatureLogs',
            'steps',
            'documentations',
            'deviations',
            'handover',
            'petugas',
            'verifier',
        ]);

        $pdf = Pdf::loadView('reports.processing-batch-pdf', [
            'batch' => $processingBatch,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(
            str_replace('/', '-', $processingBatch->batch_number) . '.pdf'
        );
    }
}
