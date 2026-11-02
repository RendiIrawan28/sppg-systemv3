<?php

namespace App\Http\Controllers;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationSession;
use App\Support\FileNaming;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreparationSessionWastePdfController extends Controller
{
    public function __invoke(Request $request, PreparationSession $session): Response
    {
        $this->authorizeSystemRecord($session, 'preparation.export');
        abort_unless(in_array($session->status, [
            OperationalReportStatus::DivisionApproved,
            OperationalReportStatus::Verified,
        ], true), 409, 'Laporan hanya dapat diunduh setelah diverifikasi Kepala Divisi Persiapan.');

        $session->load(['sppgUnit', 'petugas', 'items.returns']);

        return Pdf::loadView('reports.preparation-session-waste-pdf', [
            'session' => $session,
        ])
            ->setPaper('letter', 'portrait')
            ->download(FileNaming::report('berita-acara-limbah-persiapan', null, $session->preparation_date, 'pdf'));
    }
}
