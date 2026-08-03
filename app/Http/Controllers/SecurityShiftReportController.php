<?php

namespace App\Http\Controllers;

use App\Models\SecurityShift;
use App\Support\V3\SecurityShiftAccess;
use App\Support\V3\UnitContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityShiftReportController extends Controller
{
    public function pdf(Request $request, SecurityShift $shift, UnitContext $context)
    {
        [$unit, $shift] = $this->reportData($request, $shift, $context);

        return Pdf::loadView('reports.security-shift-report-pdf', compact('unit', 'shift'))
            ->setPaper('a4', 'landscape')
            ->stream("laporan-keamanan-{$shift->uuid}.pdf");
    }

    public function xlsx(Request $request, SecurityShift $shift, UnitContext $context): StreamedResponse
    {
        [$unit, $shift] = $this->reportData($request, $shift, $context);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Laporan Keamanan');
        $sheet->fromArray([
            [$unit->name],
            ['Laporan Shift Keamanan'],
            ["Petugas: {$shift->officer_name_snapshot}"],
            ['Mulai', $shift->started_at->format('d/m/Y H:i'), 'Selesai', ($shift->completed_at ?? $shift->scheduled_end_at)->format('d/m/Y H:i')],
            [],
            ['Jam ke', 'Target laporan', 'Waktu laporan', 'Status', 'Situasi', 'Gerbang/Akses', 'Lingkungan', 'Aktivitas Orang/Kendaraan', 'Tamu', 'Catatan'],
        ], null, 'A1');
        $sheet->mergeCells('A1:J1')->mergeCells('A2:J2')->mergeCells('A3:J3');
        $sheet->getStyle('A1:J3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:J3')->getFont()->setBold(true);

        foreach (range(1, $shift->reports_expected) as $index => $sequence) {
            $report = $shift->reports->firstWhere('sequence_number', $sequence);
            $target = $shift->started_at->copy()->addHours(SecurityShift::REPORT_INTERVAL_HOURS * $sequence);
            $sheet->fromArray([[
                $sequence,
                $target->format('d/m/Y H:i'),
                $report?->reported_at?->format('d/m/Y H:i') ?: '-',
                $report ? 'Dilaporkan' : 'Tidak dilaporkan',
                $report?->situation?->label() ?: '-',
                $report ? ($report->gate_secure ? 'Aman' : 'Perlu perhatian') : '-',
                $report ? ($report->perimeter_secure ? 'Aman' : 'Perlu perhatian') : '-',
                $report?->access_activity ?: '-',
                $report?->visitor_activity ?: '-',
                $report?->notes ?: '-',
            ]], null, 'A'.($index + 7));
        }

        $lastRow = 6 + $shift->reports_expected;
        $sheet->getStyle("A6:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A6:J6')->getFont()->setBold(true);
        $sheet->getStyle('A6:J6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A6:J{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($sheet): void {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, "laporan-keamanan-{$shift->uuid}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{0: mixed, 1: SecurityShift} */
    private function reportData(Request $request, SecurityShift $shift, UnitContext $context): array
    {
        $unit = $context->for($request->user());
        abort_unless($unit && (int) $shift->sppg_unit_id === (int) $unit->getKey(), 404);
        abort_unless(app(SecurityShiftAccess::class)->canView($request->user(), $shift), 403);

        return [$unit, $shift->load('reports')];
    }
}
