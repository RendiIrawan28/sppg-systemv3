<?php

namespace App\Http\Controllers;

use App\Models\FieldDailyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class FieldDailyReportPdfController extends Controller
{
    public function __invoke(FieldDailyReport $fieldDailyReport): Response
    {
        $this->authorizeSystemRecord($fieldDailyReport, 'field_daily_reports.export');

        $fieldDailyReport->load([
            'sppgUnit',
            'plan',
            'divisions',
            'incidents',
            'preparer',
            'approver',
        ]);

        $filename = sprintf(
            'laporan-harian-aslap-%s.pdf',
            $fieldDailyReport->report_date?->format('Y-m-d')
        );

        return Pdf::loadView('reports.field-daily-report-pdf', [
            'report' => $fieldDailyReport,
        ])
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }
}
