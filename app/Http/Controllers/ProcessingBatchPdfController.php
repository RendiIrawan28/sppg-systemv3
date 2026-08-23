<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Models\ProcessingBatch;
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
     * menggabungkan seluruh batch pada tanggal produksi yang sama.
     */
    public function production(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);

        $batches = $this->dailyBatches($processingBatch);
        $this->assertDailyReportReady($batches);

        $reportDate = $processingBatch->production_date;
        $filename = sprintf(
            'Laporan Monitoring Produksi %s.pdf',
            $reportDate?->format('d-m-Y') ?? now()->format('d-m-Y'),
        );

        return Pdf::loadView('reports.processing-monitoring-production-pdf', [
            'anchorBatch' => $processingBatch,
            'batches' => $batches,
            'reportDate' => $reportDate,
        ])->setPaper('a4', 'landscape')->download($filename);
    }

    /**
     * Export Pemantauan Suhu Pengolahan & Penyajian HARIAN.
     * Semua temperature log final dari seluruh batch pada tanggal yang sama digabung.
     */
    public function temperature(Request $request, ProcessingBatch $processingBatch): Response
    {
        $this->authorizeExport($processingBatch);

        $batches = $this->dailyBatches($processingBatch);
        $this->assertDailyReportReady($batches);

        $logs = $batches
            ->flatMap(fn (ProcessingBatch $batch) => $batch->temperatureLogs)
            ->filter(fn ($log): bool => $log->checkpoint?->value === 'final')
            ->sortBy(fn ($log) => $log->checked_at?->timestamp ?? PHP_INT_MAX)
            ->values();

        $reportDate = $processingBatch->production_date;
        $filename = sprintf(
            'Pemantauan Suhu Pengolahan Penyajian %s.pdf',
            $reportDate?->format('d-m-Y') ?? now()->format('d-m-Y'),
        );

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

        return ProcessingBatch::query()
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
            ->where('state', '!=', ProcessingBatchState::Cancelled->value)
            ->orderByRaw('CASE WHEN started_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, ProcessingBatch> $batches */
    private function assertDailyReportReady(Collection $batches): void
    {
        abort_if($batches->isEmpty(), 404, 'Belum ada batch Pengolahan pada tanggal tersebut.');

        $unfinished = $batches->first(
            fn (ProcessingBatch $batch): bool => $batch->state !== ProcessingBatchState::Completed,
        );

        abort_if(
            $unfinished !== null,
            403,
            'Laporan harian belum dapat diekspor karena masih ada batch yang belum selesai.',
        );

        $unverified = $batches->first(
            fn (ProcessingBatch $batch): bool => $batch->status !== OperationalReportStatus::Verified,
        );

        abort_if(
            $unverified !== null,
            403,
            'Laporan harian hanya dapat diekspor setelah seluruh batch tanggal tersebut disetujui Kepala SPPG.',
        );
    }
}
