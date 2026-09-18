<?php

namespace App\Http\Controllers;

use App\Models\FieldDailyReport;
use App\Support\FileNaming;
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

        $filename = FileNaming::report('laporan-harian-aslap', null, $fieldDailyReport->report_date, 'pdf');

        return Pdf::loadView('reports.field-daily-report-pdf', [
            'report' => $fieldDailyReport,
        ])
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }
}
