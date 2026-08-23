<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreparationSessionCalculationPdfController extends Controller
{
    public function __invoke(Request $request, PreparationSession $session): Response
    {
        $this->authorizeSystemRecord($session, 'preparation.export');
        abort_unless($session->status === OperationalReportStatus::Verified, 409, 'Laporan hanya dapat diunduh setelah disetujui Kepala SPPG.');

        $session->load(['sppgUnit', 'petugas', 'items.returns', 'items.resultDocumentation']);

        return Pdf::loadView('reports.preparation-session-calculation-pdf', [
            'session' => $session,
        ])
            ->setPaper('a4', 'portrait')
            ->download('Berita-Acara-Perhitungan-'.str_replace('/', '-', $session->session_number).'.pdf');
    }
}
