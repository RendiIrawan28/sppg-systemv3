<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use App\Models\PortioningSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortioningSessionPdfController extends Controller
{
    public function __invoke(Request $request, PortioningSession $portioningSession): Response
    {
        $this->authorizeSystemRecord($portioningSession, 'portioning.export');
        $portioningSession->loadMissing('verifier.roles');
        abort_unless(
            $portioningSession->status === OperationalReportStatus::Verified
                && $portioningSession->verifier?->hasRole(UserRole::KepalaSppg->value),
            403,
            'Laporan hanya dapat diekspor setelah diverifikasi Kepala SPPG.',
        );

        $portioningSession->load([
            'sppgUnit',
            'routeAllocations',
            'routeRecords',
            'leftoverRecords',
            'petugas',
            'divisionApprover',
            'verifier',
        ]);

        $pdf = Pdf::loadView('reports.portioning-session-pdf', [
            'session' => $portioningSession,
        ])->setPaper('a4', 'landscape');

        return $pdf->download(
            str_replace('/', '-', $portioningSession->session_number).'-form-pengawasan-pengemasan.pdf',
        );
    }
}
