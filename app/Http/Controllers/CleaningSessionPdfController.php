<?php

namespace App\Http\Controllers;

use App\Models\CleaningSession;
use App\Support\FileNaming;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CleaningSessionPdfController extends Controller
{
    public function __invoke(Request $request, CleaningSession $cleaningSession): Response
    {
        $this->authorizeSystemRecord($cleaningSession, 'cleaning.export');

        $cleaningSession->load([
            'sppgUnit',
            'cleaningArea',
            'checklistItems.checker',
            'chemicalUsages',
            'documentations',
            'findings.resolver',
            'wasteRecords',
            'petugas',
            'supervisor',
            'verifier',
        ]);

        $pdf = Pdf::loadView('reports.cleaning-session-pdf', [
            'session' => $cleaningSession,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(FileNaming::report(
            'laporan-kebersihan',
            $cleaningSession->cleaningArea?->name,
            $cleaningSession->scheduled_date,
            'pdf',
        ));
    }
}
