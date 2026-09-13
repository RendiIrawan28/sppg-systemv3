<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreparationSessionCalculationPdfController extends Controller
{
    public function __invoke(Request $request, PreparationSession $session): Response
    {
        $this->authorizeSystemRecord($session, 'preparation.export');
        $reportDate = $session->preparation_date?->toDateString();
        abort_unless($reportDate, 422, 'Tanggal Persiapan belum tersedia.');

        $sessions = PreparationSession::query()
            ->with(['sppgUnit', 'petugas', 'items.resultDocumentation'])
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->whereDate('preparation_date', $reportDate)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
        $this->assertDailyReportReady($sessions);

        return Pdf::loadView('reports.preparation-session-calculation-pdf', [
            'sessions' => $sessions,
            'reportDate' => $session->preparation_date,
        ])
            ->setPaper('a4', 'portrait')
            ->download('Berita-Acara-Perhitungan-Persiapan-'.$session->preparation_date->format('d-m-Y').'.pdf');
    }

    /** @param Collection<int, PreparationSession> $sessions */
    private function assertDailyReportReady(Collection $sessions): void
    {
        abort_if($sessions->isEmpty(), 404, 'Belum ada pekerjaan Persiapan pada tanggal tersebut.');
        abort_if(
            $sessions->contains(fn (PreparationSession $item): bool => $item->state !== 'completed'),
            403,
            'Laporan harian belum dapat diekspor karena masih ada pekerjaan Persiapan yang belum selesai.',
        );
        abort_if(
            $sessions->contains(fn (PreparationSession $item): bool => $item->status !== OperationalReportStatus::Verified),
            403,
            'Laporan harian hanya dapat diekspor setelah seluruh pekerjaan tanggal tersebut disetujui Kepala SPPG.',
        );
    }
}
