<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\SppgUnit;
use App\Support\V3\UnitContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function pdf(Request $request, UnitContext $context)
    {
        abort_unless($request->user()->is_super_admin || $request->user()->can('attendance.export'), 403);
        [$unit, $from, $to, $sessions] = $this->reportData($request, $context);

        return Pdf::loadView('reports.attendance-report-pdf', compact('unit', 'from', 'to', 'sessions'))
            ->setPaper('a4', 'landscape')
            ->stream("rekap-presensi-{$from}-{$to}.pdf");
    }

    public function xlsx(Request $request, UnitContext $context): StreamedResponse
    {
        abort_unless($request->user()->is_super_admin || $request->user()->can('attendance.export'), 403);
        [$unit, $from, $to, $sessions] = $this->reportData($request, $context);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Rekap Presensi');
        $sheet->fromArray([
            [$unit->name],
            ["Rekap Presensi Relawan {$from} s.d. {$to}"],
            [],
            ['No', 'Tanggal Kerja', 'Nama Relawan', 'UID Kartu', 'Divisi/Peran', 'Status', 'Jam Masuk', 'Jam Pulang', 'Durasi', 'Sumber', 'Catatan'],
        ], null, 'A1');
        $sheet->mergeCells('A1:K1')->mergeCells('A2:K2');
        $sheet->getStyle('A1:K2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:K2')->getFont()->setBold(true);

        foreach ($sessions as $index => $session) {
            $minutes = $session->durationMinutes();
            $sheet->fromArray([[
                $index + 1,
                $session->work_date->format('d/m/Y'),
                $session->user->name,
                $session->user->employee_number,
                $session->user->divisions->first()?->name ?: UserRole::labelFor($session->user->roles->first()?->name),
                $this->statusLabel($session->status),
                $session->check_in_at?->format('H:i'),
                $session->check_out_at?->format('d/m/Y H:i'),
                $minutes === null ? null : intdiv($minutes, 60).' jam '.($minutes % 60).' menit',
                $session->source,
                $session->notes,
            ]], null, 'A'.($index + 5));
        }

        $lastRow = max(5, $sessions->count() + 4);
        $sheet->getStyle("A4:K{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A4:K4')->getFont()->setBold(true);
        $sheet->getStyle('A4:K4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($sheet): void {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, "rekap-presensi-{$from}-{$to}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{0: SppgUnit, 1: string, 2: string, 3: Collection<int, AttendanceSession>} */
    private function reportData(Request $request, UnitContext $context): array
    {
        $data = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);
        $unit = $context->for($request->user());
        abort_unless($unit, 403);
        $sessions = AttendanceSession::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereBetween('work_date', [$data['date_from'], $data['date_to']])
            ->with(['user.roles', 'user.divisions'])
            ->orderBy('work_date')->orderBy('check_in_at')->get();

        return [$unit, $data['date_from'], $data['date_to'], $sessions];
    }

    private function statusLabel(string $status): string
    {
        return ['present' => 'Hadir', 'permission' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Tidak hadir'][$status] ?? $status;
    }
}
