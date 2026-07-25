<?php

namespace App\Http\Controllers;

use App\Models\ProcurementRequest;
use App\Support\V3\OperationsPresentation;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ProcurementRequestExportController extends Controller
{
    public function pdf(ProcurementRequest $procurement): Response
    {
        $this->authorizeExport($procurement);
        $this->loadExportRelations($procurement);

        return Pdf::loadView('reports.procurement-request-pdf', [
            'procurement' => $procurement,
            'statusLabel' => $this->statusLabel($procurement),
        ])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($procurement, 'pdf'));
    }

    public function excel(ProcurementRequest $procurement): BinaryFileResponse
    {
        $this->authorizeExport($procurement);
        $this->loadExportRelations($procurement);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pengadaan Bahan');

        $sheet->fromArray([
            ['DAFTAR PENGADAAN BAHAN'],
            ['Unit SPPG', $procurement->sppgUnit?->name],
            ['Nomor Pengadaan', $procurement->request_number],
            ['Tanggal Permintaan', $procurement->request_date?->format('d-m-Y')],
            ['Tanggal Dibutuhkan', $procurement->needed_date?->format('d-m-Y')],
            ['Status', $this->statusLabel($procurement)],
            ['Disetujui Kepala SPPG', $procurement->priceFinalizer?->name ?? '-'],
            ['Catatan', $procurement->notes],
            [],
            ['No', 'Kode', 'Bahan', 'Supplier', 'Jumlah', 'Satuan', 'Berat (kg)', 'Harga Satuan', 'Subtotal', 'Catatan Item'],
        ], null, 'A1');

        $row = 11;
        foreach ($procurement->items as $index => $item) {
            $sheet->fromArray([[
                $index + 1,
                $item->ingredient_code_snapshot,
                $item->ingredient_name_snapshot,
                $item->supplier?->name ?? '-',
                (float) ($item->approved_quantity ?: $item->requested_quantity),
                $item->unit_snapshot,
                (float) ($item->approved_quantity_kg ?: $item->requested_quantity_kg),
                (float) $item->estimated_unit_price,
                (float) $item->estimated_total_price,
                $item->notes,
            ]], null, "A{$row}");
            $row++;
        }

        $sheet->fromArray([[
            '', '', '', '', '', '', '', 'TOTAL',
            (float) $procurement->estimated_total_amount,
            '',
        ]], null, "A{$row}");

        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A10:J10')->getFont()->setBold(true);
        $sheet->getStyle('A10:J10')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getStyle("A10:J{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("H11:I{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("H{$row}:I{$row}")->getFont()->setBold(true);
        $sheet->freezePane('A11');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'procurement-request-');
        (new Xlsx($spreadsheet))->save($temporaryFile);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($temporaryFile, $this->filename($procurement, 'xlsx'))
            ->deleteFileAfterSend(true);
    }

    private function authorizeExport(ProcurementRequest $procurement): void
    {
        $this->authorizeSystemRecord($procurement, 'procurement.export');

        abort_unless(in_array($procurement->status, [
            ProcurementRequest::STATUS_APPROVED,
            ProcurementRequest::STATUS_ORDERED,
        ], true), 404, 'Dokumen ekspor tersedia setelah pengadaan disetujui Kepala SPPG.');
    }

    private function loadExportRelations(ProcurementRequest $procurement): void
    {
        $procurement->loadMissing([
            'sppgUnit',
            'items.supplier',
            'creator',
            'submitter',
            'approver',
            'priceFinalizer',
        ]);
    }

    private function statusLabel(ProcurementRequest $procurement): string
    {
        return OperationsPresentation::procurementStatuses()[$procurement->status]
            ?? str($procurement->status)->headline()->toString();
    }

    private function filename(ProcurementRequest $procurement, string $extension): string
    {
        $number = str_replace(['/', '\\'], '-', (string) $procurement->request_number);

        return "pengadaan-bahan-{$number}.{$extension}";
    }
}
