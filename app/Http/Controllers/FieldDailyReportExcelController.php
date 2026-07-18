<?php

namespace App\Http\Controllers;

use App\Models\FieldDailyReport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldDailyReportExcelController extends Controller
{
    public function __invoke(FieldDailyReport $fieldDailyReport): BinaryFileResponse
    {
        $this->authorizeSystemRecord($fieldDailyReport, 'field_daily_reports.export');

        $fieldDailyReport->load(['sppgUnit', 'plan', 'divisions', 'incidents']);
        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $summary->fromArray([
            ['LAPORAN HARIAN ASISTEN LAPANGAN'],
            ['Unit SPPG', $fieldDailyReport->sppgUnit?->name],
            ['Nomor', $fieldDailyReport->report_number],
            ['Tanggal', $fieldDailyReport->report_date?->format('d-m-Y')],
            ['Rencana', $fieldDailyReport->plan?->plan_number],
            ['Status', $fieldDailyReport->status?->label()],
            [],
            ['INDIKATOR', 'NILAI'],
            ['Penerima Terdaftar', $fieldDailyReport->planned_beneficiaries],
            ['Penerima Terkonfirmasi', $fieldDailyReport->confirmed_beneficiaries],
            ['Penerima Aktual', $fieldDailyReport->actual_beneficiaries],
            ['Porsi Direncanakan', $fieldDailyReport->planned_portions],
            ['Porsi Diproduksi', $fieldDailyReport->produced_portions],
            ['Porsi Diporsikan', $fieldDailyReport->portioned_portions],
            ['Porsi Terkirim', $fieldDailyReport->delivered_portions],
            ['Porsi Kembali', $fieldDailyReport->returned_portions],
            ['Tujuan Direncanakan', $fieldDailyReport->planned_destinations],
            ['Tujuan Berhasil', $fieldDailyReport->completed_destinations],
            ['Tujuan Gagal', $fieldDailyReport->failed_destinations],
            ['Tujuan Terlambat', $fieldDailyReport->late_destinations],
            ['Ompreng Dikirim', $fieldDailyReport->containers_sent],
            ['Ompreng Kembali', $fieldDailyReport->containers_returned],
            ['Ompreng Rusak', $fieldDailyReport->containers_damaged],
            ['Ompreng Hilang', $fieldDailyReport->containers_lost],
            ['Insiden Terbuka', $fieldDailyReport->open_incidents],
            ['Insiden Selesai', $fieldDailyReport->resolved_incidents],
            [],
            ['Ringkasan Operasional', $fieldDailyReport->operational_summary],
            ['Kendala', $fieldDailyReport->obstacles],
            ['Evaluasi', $fieldDailyReport->evaluation],
            ['Tindak Lanjut', $fieldDailyReport->follow_up],
            ['Rekomendasi', $fieldDailyReport->recommendations],
        ], null, 'A1');
        $summary->mergeCells('A1:B1');
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summary->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getStyle('A8:B8')->getFont()->setBold(true);
        $summary->getColumnDimension('A')->setWidth(30);
        $summary->getColumnDimension('B')->setWidth(80);
        $summary->getStyle('B28:B32')->getAlignment()->setWrapText(true);

        $divisions = $spreadsheet->createSheet();
        $divisions->setTitle('Status Divisi');
        $divisions->fromArray([[
            'Divisi', 'Total', 'Draft', 'Diajukan', 'Revisi', 'Terverifikasi', 'Status', 'Aktivitas Terakhir',
        ]], null, 'A1');
        $row = 2;
        foreach ($fieldDailyReport->divisions as $division) {
            $divisions->fromArray([[
                $division->division_name,
                $division->total_records,
                $division->draft_records,
                $division->submitted_records,
                $division->revision_records,
                $division->verified_records,
                $division->completion_status,
                $division->last_activity_at?->format('d-m-Y H:i'),
            ]], null, "A{$row}");
            $row++;
        }
        $divisions->getStyle('A1:H1')->getFont()->setBold(true);
        $divisions->getStyle("A1:H".max(1, $row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'H') as $column) {
            $divisions->getColumnDimension($column)->setAutoSize(true);
        }

        $incidents = $spreadsheet->createSheet();
        $incidents->setTitle('Insiden');
        $incidents->fromArray([[
            'Divisi', 'Kategori', 'Keparahan', 'Status', 'Judul', 'Deskripsi', 'Tindakan/Penyelesaian',
        ]], null, 'A1');
        $row = 2;
        foreach ($fieldDailyReport->incidents as $incident) {
            $incidents->fromArray([[
                $incident->division_code,
                $incident->category,
                $incident->severity,
                $incident->status,
                $incident->title,
                $incident->description,
                $incident->action_or_resolution,
            ]], null, "A{$row}");
            $row++;
        }
        $incidents->getStyle('A1:G1')->getFont()->setBold(true);
        $incidents->getStyle("A1:G".max(1, $row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'G') as $column) {
            $incidents->getColumnDimension($column)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $temporaryFile = tempnam(sys_get_temp_dir(), 'field-report-');
        (new Xlsx($spreadsheet))->save($temporaryFile);

        $filename = sprintf(
            'laporan-harian-aslap-%s.xlsx',
            $fieldDailyReport->report_date?->format('Y-m-d')
        );

        return response()->download($temporaryFile, $filename)->deleteFileAfterSend(true);
    }
}
