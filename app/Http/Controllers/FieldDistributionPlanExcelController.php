<?php

namespace App\Http\Controllers;

use App\Models\FieldDistributionPlan;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldDistributionPlanExcelController extends Controller
{
    public function __invoke(Request $request, FieldDistributionPlan $fieldDistributionPlan): BinaryFileResponse
    {
        $this->authorizeExport($request, $fieldDistributionPlan);

        $fieldDistributionPlan->loadMissing(['sppgUnit', 'destinations.recipientGroups']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rencana Distribusi');

        $sheet->fromArray([
            ['RENCANA DISTRIBUSI HARIAN'],
            ['Unit SPPG', $fieldDistributionPlan->sppgUnit?->name],
            ['Nomor', $fieldDistributionPlan->plan_number],
            ['Tanggal Distribusi', $fieldDistributionPlan->distribution_date?->format('d-m-Y')],
            ['Tanggal Layanan', $fieldDistributionPlan->service_date?->format('d-m-Y')],
            ['Tanggal Produksi', $fieldDistributionPlan->production_date?->format('d-m-Y')],
            ['Menu', $fieldDistributionPlan->menu_name_snapshot],
            ['Status', $fieldDistributionPlan->status?->label()],
            [],
            [
                'No', 'Jenis', 'Kode', 'Tujuan', 'Alamat', 'PIC', 'Telepon', 'Rute',
                'Terdaftar', 'Terkonfirmasi', 'Porsi Kecil', 'Porsi Besar', 'Total',
                'Status Konfirmasi', 'Catatan',
            ],
        ], null, 'A1');

        $row = 11;
        foreach ($fieldDistributionPlan->destinations as $index => $destination) {
            $sheet->fromArray([[
                $index + 1,
                $destination->destination_type,
                $destination->destination_code_snapshot,
                $destination->destination_name_snapshot,
                $destination->address_snapshot,
                $destination->contact_name_snapshot,
                $destination->contact_phone_snapshot,
                $destination->route_name,
                $destination->registered_beneficiaries,
                $destination->confirmed_beneficiaries,
                $destination->small_portions,
                $destination->large_portions,
                $destination->total_portions,
                $destination->confirmation_status,
                $destination->special_notes,
            ]], null, "A{$row}");
            $row++;
        }

        $sheet->mergeCells('A1:O1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A10:O10')->getFont()->setBold(true);
        $sheet->getStyle('A10:O'.max(10, $row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A11');

        foreach (range('A', 'O') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'field-distribution-plan-');
        (new Xlsx($spreadsheet))->save($temporaryFile);

        return response()
            ->download($temporaryFile, $this->filename($fieldDistributionPlan))
            ->deleteFileAfterSend(true);
    }

    private function authorizeExport(Request $request, FieldDistributionPlan $fieldDistributionPlan): void
    {
        $this->authorizeSystemRecord($fieldDistributionPlan, 'field_planning.export');
    }

    private function filename(FieldDistributionPlan $plan): string
    {
        return sprintf(
            'rencana-distribusi-%s.xlsx',
            str_replace(['/', '\\'], '-', (string) $plan->plan_number)
        );
    }
}
