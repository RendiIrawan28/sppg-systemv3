<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Services\AttendanceReportData;
use App\Support\V3\UnitContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function pdf(Request $request, UnitContext $context)
    {
        $data = $this->reportData($request, $context);

        $pdf = Pdf::loadView('reports.attendance-report-pdf', $data)->setPaper('a4', 'landscape');
        $pdf->render();
        $pdf->getDomPDF()->getCanvas()->page_text(720, 575, 'Halaman {PAGE_NUM}/{PAGE_COUNT}', null, 7, [0.3, 0.3, 0.3]);

        return $pdf->stream("rekap-presensi-{$data['from']}-{$data['to']}.pdf");
    }

    public function xlsx(Request $request, UnitContext $context): StreamedResponse
    {
        $data = $this->reportData($request, $context);
        $workbook = new Spreadsheet;
        $usedNames = ['rekap semua'];
        if (! $data['divisionId']) {
            $this->fillSheet($workbook->getActiveSheet()->setTitle('Rekap Semua'), $data, $data['groups']->flatMap(fn ($group) => $group['sessions'])->values(), true);
        } else {
            $name = $data['sessions']->first()?->division_name_snapshot ?? $data['divisionLabel'];
            $this->fillSheet($workbook->getActiveSheet()->setTitle($this->sheetName($name, $usedNames)), $data, $data['sessions'], false, $name);
        }
        foreach ($data['divisionId'] ? [] : $data['groups'] as $group) {
            $sheet = $workbook->createSheet();
            $sheet->setTitle($this->sheetName($group['name'], $usedNames));
            $this->fillSheet($sheet, $data, $group['sessions'], false, $group['name']);
        }
        if ($workbook->getSheetCount() === 0) {
            $this->fillSheet($workbook->createSheet()->setTitle('Rekap Presensi'), $data, collect(), false, $data['divisionLabel']);
        }
        $workbook->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($workbook): void {
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, "rekap-presensi-{$data['from']}-{$data['to']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function fillSheet(Worksheet $sheet, array $data, Collection $sessions, bool $includeDivision, string $divisionName = 'Semua Divisi'): void
    {
        $headers = ['No', 'Tanggal', ...($includeDivision ? ['Divisi'] : []), 'Nama Pegawai', 'UID Kartu', 'Shift', 'Jadwal Masuk', 'Jadwal Pulang', 'Status', 'Keterangan', 'Masuk Aktual', 'Pulang Aktual', 'Durasi', 'Sumber', 'Catatan'];
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setCellValueExplicit('A1', $data['unit']->name, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', 'REKAP PRESENSI PEGAWAI', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', $divisionName.' | '.Carbon::parse($data['from'])->format('d-m-Y').' s.d. '.Carbon::parse($data['to'])->format('d-m-Y'), DataType::TYPE_STRING);
        foreach ([1, 2, 3] as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }
        $sheet->fromArray([$headers], null, 'A5');
        foreach ($sessions->values() as $index => $session) {
            $minutes = $session->durationMinutes();
            $values = [$index + 1, $session->work_date->format('d-m-Y'), ...($includeDivision ? [$session->division_name_snapshot ?? 'Tanpa Divisi'] : []),
                $session->user?->name ?? '-', $session->user?->employee_number ?? '-', $session->shift_name_snapshot ?? '-',
                $session->scheduled_check_in_at?->format('d-m-Y H:i') ?? '-', $session->scheduled_check_out_at?->format('d-m-Y H:i') ?? '-',
                $session->statusLabel(), $session->attendanceRemark(), $session->check_in_at?->format('d-m-Y H:i') ?? '-', $session->check_out_at?->format('d-m-Y H:i') ?? '-',
                $minutes === null ? '-' : intdiv($minutes, 60).' jam '.($minutes % 60).' menit', $session->sourceLabel(), $session->notes ?? '-'];
            foreach ($values as $column => $value) {
                // Keep leading zeros and prevent user-controlled spreadsheet formulas.
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($column + 1).($index + 6), (string) $value, DataType::TYPE_STRING);
            }
        }
        $lastRow = max(6, $sessions->count() + 5);
        $sheet->getStyle("A1:{$lastColumn}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A1:{$lastColumn}2")->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("A5:{$lastColumn}5")->getFont()->setBold(true);
        $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === 1 ? 6 : 22);
        }
        $sheet->getColumnDimension($lastColumn)->setWidth(35);
        $sheet->freezePane('A6');
        $sheet->setAutoFilter("A5:{$lastColumn}{$lastRow}");
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5)->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
    }

    private function sheetName(string $name, array &$used): string
    {
        $base = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name), " '\t\n\r\0\x0B");
        $base = mb_substr($base ?: 'Tanpa Divisi', 0, 31);
        $candidate = $base;
        $suffix = 2;
        while (in_array(mb_strtolower($candidate), $used, true)) {
            $tail = ' ('.$suffix++.')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }
        $used[] = mb_strtolower($candidate);

        return $candidate;
    }

    private function reportData(Request $request, UnitContext $context): array
    {
        abort_unless($request->user()->is_super_admin || $request->user()->can('attendance.export'), 403);
        $data = $request->validate(['date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'division_id' => ['nullable', 'integer', 'exists:divisions,id'], 'search' => ['nullable', 'string', 'max:150']]);
        $unit = $context->for($request->user());
        abort_unless($unit, 403);
        $divisionId = (int) ($data['division_id'] ?? 0) ?: null;
        $from = $data['date_from'];
        $to = $data['date_to'];
        $service = app(AttendanceReportData::class);
        $sessions = $service->sessions($unit->id, $from, $to, $divisionId, $data['search'] ?? '');
        $groups = $service->groups($sessions);
        $divisionLabel = $divisionId ? (Division::find($divisionId)?->name ?? 'Tanpa Divisi') : 'Semua Divisi';

        return compact('unit', 'from', 'to', 'sessions', 'groups', 'divisionId', 'divisionLabel');
    }
}
