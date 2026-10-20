<?php

namespace App\Http\Controllers;

use App\Services\FieldWeeklyReportBuilder;
use App\Support\FileNaming;
use App\Support\V3\SystemUnit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FieldWeeklyReportPdfController extends Controller
{
    public function __invoke(Request $request, FieldWeeklyReportBuilder $builder): Response
    {
        $this->authorizeSystemUnit('field_daily_reports.export');
        $unit = app(SystemUnit::class)->get();
        abort_unless($unit, 403);

        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'menu_photos' => ['sometimes', 'boolean'],
            'activity_photos' => ['sometimes', 'boolean'],
            'waybills' => ['sometimes', 'boolean'],
        ]);

        $monday = CarbonImmutable::parse($data['start'])->startOfWeek();
        abort_unless($monday->toDateString() === $data['start'], 422, 'Tanggal awal harus hari Senin.');
        $options = [
            'menu_photos' => (bool) ($data['menu_photos'] ?? true),
            'activity_photos' => (bool) ($data['activity_photos'] ?? true),
            'waybills' => (bool) ($data['waybills'] ?? true),
        ];
        $report = $builder->build($unit, $monday, $options);
        abort_if($report['daily_count'] === 0, 422, 'Tidak ada laporan harian pada minggu yang dipilih.');

        $name = FileNaming::report('laporan-mingguan-aslap', $unit->code, $monday, 'pdf', $report['end']);

        return Pdf::loadView('reports.field-weekly-report-pdf', $report)
            ->setPaper('a4', 'portrait')
            ->download($name);
    }
}
