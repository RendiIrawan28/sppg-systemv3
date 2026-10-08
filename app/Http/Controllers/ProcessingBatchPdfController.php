<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Models\ProcessingBatch;
use App\Support\FileNaming;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcessingBatchPdfController extends Controller
{
    public function __invoke(Request $request, ProcessingBatch $processingBatch): Response
    {
        return $this->production($request, $processingBatch);
    }

    /**
     * Export Monitoring Produksi HARIAN.
     *
     * ProcessingBatch tetap menjadi unit operasional per batch, tetapi dokumen resmi
     * menggabungkan batch selesai dan terverifikasi pada tanggal produksi yang sama.
     */
    public function production(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);

        $batches = $this->dailyBatches($processingBatch);

        $reportDate = $processingBatch->production_date;
        $filename = FileNaming::report('laporan-monitoring-produksi', null, $reportDate, 'pdf');

        return Pdf::loadView('reports.processing-monitoring-production-pdf', [
            'anchorBatch' => $processingBatch,
            'batches' => $batches,
            'reportDate' => $reportDate,
        ])->setPaper('a4', 'landscape')->download($filename);
    }

    /**
     * Export Pemantauan Suhu Pengolahan & Penyajian HARIAN.
     * Semua temperature log final dari batch yang terverifikasi pada tanggal yang sama digabung.
     */
    public function temperature(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);

        $batches = $this->dailyBatches($processingBatch);

        $logs = $batches
            ->flatMap(fn (ProcessingBatch $batch) => $batch->temperatureLogs)
            ->filter(fn ($log): bool => $log->checkpoint?->value === 'final')
            ->sortBy(fn ($log) => $log->checked_at?->timestamp ?? PHP_INT_MAX)
            ->values();

        $reportDate = $processingBatch->production_date;
        $filename = FileNaming::report('pemantauan-suhu-pengolahan', null, $reportDate, 'pdf');

        return Pdf::loadView('reports.processing-temperature-monitoring-pdf', [
            'anchorBatch' => $processingBatch,
            'batches' => $batches,
            'logs' => $logs,
            'reportDate' => $reportDate,
        ])->setPaper('a4', 'landscape')->download($filename);
    }

    private function authorizeExport(ProcessingBatch $batch): void
    {
        $this->authorizeSystemRecord($batch, 'processing.export');
    }

    /** @return Collection<int, ProcessingBatch> */
    private function dailyBatches(ProcessingBatch $anchor): Collection
    {
        $date = $anchor->production_date?->toDateString();
        abort_unless($date, 422, 'Tanggal produksi batch belum tersedia.');
        abort_unless(
            $anchor->state === ProcessingBatchState::Completed
                && $anchor->status === OperationalReportStatus::Verified,
            403,
            'Pilih batch yang sudah selesai dan disetujui Kepala SPPG untuk mengekspor laporan harian.',
        );

        $batches = ProcessingBatch::query()
            ->with([
                'sppgUnit',
                'materialUsages',
                'temperatureLogs.measuredBy',
                'documentations',
                'petugas',
                'divisionApprover',
                'verifier',
            ])
            ->where('sppg_unit_id', $anchor->sppg_unit_id)
            ->whereDate('production_date', $date)
            ->where('state', ProcessingBatchState::Completed->value)
            ->where('status', OperationalReportStatus::Verified->value)
            ->orderByRaw('CASE WHEN started_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
        abort_if($batches->isEmpty(), 404, 'Belum ada batch Pengolahan yang disetujui pada tanggal tersebut.');

        return $batches;
    }
}
