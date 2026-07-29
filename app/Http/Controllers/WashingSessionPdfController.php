<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\WashingSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WashingSessionPdfController extends Controller
{
    public function __invoke(Request $request, WashingSession $washingSession): Response
    {
        $this->authorizeSystemRecord($washingSession, 'washing.export');

        abort_unless(
            $washingSession->status === OperationalReportStatus::Verified,
            409,
            'Laporan hanya dapat diunduh setelah disetujui Kepala SPPG.',
        );

        $date = $washingSession->washing_date?->toDateString();
        abort_unless($date !== null, 409, 'Tanggal Pencucian belum tersedia.');

        $sessions = WashingSession::query()
            ->where('sppg_unit_id', $washingSession->sppg_unit_id)
            ->whereDate('washing_date', $date)
            ->where('status', OperationalReportStatus::Verified->value)
            ->with([
                'sppgUnit',
                'containerCollectionRun', 'distributionRun',
                'checklistItems.checker',
                'wasteRecords',
                'documentations',
                'petugas',
                'divisionApprover',
                'verifier',
            ])
            ->orderBy('id')
            ->get();

        abort_if($sessions->isEmpty(), 409, 'Tidak ada laporan Pencucian terverifikasi pada tanggal tersebut.');

        return Pdf::loadView('reports.washing-session-pdf', [
            'session' => $washingSession,
            'sessions' => $sessions,
        ])
            ->setPaper('a4', 'landscape')
            ->download('Laporan-Harian-Pencucian-'.$date.'.pdf');
    }
}
