<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\ProcessingBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcessingBatchPdfController extends Controller
{
    public function __invoke(Request $request, ProcessingBatch $processingBatch): Response
    {
        return $this->production($request, $processingBatch);
    }

    public function production(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);
        $this->loadReportRelations($processingBatch);

        return Pdf::loadView('reports.processing-monitoring-production-pdf', [
            'batch' => $processingBatch,
        ])->setPaper('a4', 'landscape')->download(
            str_replace('/', '-', $processingBatch->batch_number).'-monitoring-produksi.pdf',
        );
    }

    public function temperature(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);
        $this->loadReportRelations($processingBatch);

        return Pdf::loadView('reports.processing-temperature-monitoring-pdf', [
            'batch' => $processingBatch,
        ])->setPaper('a4', 'landscape')->download(
            str_replace('/', '-', $processingBatch->batch_number).'-pemantauan-suhu.pdf',
        );
    }

    private function authorizeExport(ProcessingBatch $batch): void
    {
        $this->authorizeSystemRecord($batch, 'processing.export');
        abort_unless(
            $batch->status === OperationalReportStatus::Verified,
            403,
            'Laporan hanya dapat diekspor setelah disetujui Kepala SPPG.',
        );
    }

    private function loadReportRelations(ProcessingBatch $batch): void
    {
        $batch->load([
            'sppgUnit',
            'materialUsages',
            'preparationOutputWithdrawals.output',
            'temperatureLogs',
            'documentations',
            'petugas',
            'divisionApprover',
            'verifier',
        ]);
    }
}
