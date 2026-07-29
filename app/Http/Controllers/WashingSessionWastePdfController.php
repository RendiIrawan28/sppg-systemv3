<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\WashingSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WashingSessionWastePdfController extends Controller
{
    public function __invoke(Request $request, WashingSession $washingSession): Response
    {
        $this->authorizeSystemRecord($washingSession, 'washing.export');

        abort_unless(
            $washingSession->status === OperationalReportStatus::Verified,
            409,
            'Berita acara limbah hanya dapat diunduh setelah laporan disetujui Kepala SPPG.',
        );

        abort_unless(
            $washingSession->has_food_waste === true && $washingSession->wasteRecords()->exists(),
            409,
            'Sesi ini tidak memiliki pencatatan limbah makanan.',
        );

        $washingSession->load(['sppgUnit', 'containerCollectionRun', 'distributionRun', 'petugas', 'wasteRecords']);

        return Pdf::loadView('reports.washing-session-waste-pdf', [
            'session' => $washingSession,
        ])
            ->setPaper('letter', 'portrait')
            ->download(
                'Berita-Acara-Limbah-Pencucian-'.str_replace('/', '-', $washingSession->session_number).'.pdf',
            );
    }
}
