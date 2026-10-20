<?php

namespace App\Http\Controllers;

use App\Models\FieldDistributionPlan;
use App\Support\FieldDistributionPlanGroupExport;
use App\Support\FileNaming;
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
        $spreadsheet = $this->buildSpreadsheet($fieldDistributionPlan);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'field-distribution-plan-');
        (new Xlsx($spreadsheet))->save($temporaryFile);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($temporaryFile, $this->filename($fieldDistributionPlan))
            ->deleteFileAfterSend(true);
    }

    private function buildSpreadsheet(FieldDistributionPlan $plan): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rencana Distribusi');

        $sheet->fromArray([
            ['RENCANA DISTRIBUSI HARIAN'],
            ['Unit SPPG', $plan->sppgUnit?->name],
            ['Nomor', $plan->plan_number],
            ['Tanggal Distribusi', $plan->distribution_date?->format('d-m-Y')],
            ['Tanggal Layanan', $plan->service_date?->format('d-m-Y')],
            ['Tanggal Produksi', $plan->production_date?->format('d-m-Y')],
            ['Menu', $plan->menu_name_snapshot],
            ['Status', $plan->status?->label()],
            [],
            [
                'No', 'Jenis', 'Kode', 'Tujuan', 'Kelompok Penerima', 'Rute',
                'Terdaftar', 'Terkonfirmasi', 'Porsi Kecil', 'Porsi Besar', 'Total',
                'Status Konfirmasi', 'Catatan',
            ],
        ], null, 'A1');

        $row = 11;
        foreach ($plan->destinations as $index => $destination) {
            $sheet->fromArray([[
                $index + 1,
                $destination->destination_type,
                $destination->destination_code_snapshot,
                $destination->destination_name_snapshot,
                FieldDistributionPlanGroupExport::destinationSummary($destination->recipientGroups),
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

        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A10:M10')->getFont()->setBold(true);
        $sheet->getStyle('A10:M'.max(10, $row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A11');
        $sheet->setAutoFilter('A10:M'.max(10, $row - 1));

        foreach (['A' => 6, 'B' => 12, 'C' => 13, 'D' => 34,
            'E' => 35, 'F' => 16, 'G' => 13, 'H' => 16,
            'I' => 13, 'J' => 13, 'K' => 12, 'L' => 20, 'M' => 35] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        if ($row > 11) {
            $sheet->getStyle('E11:E'.($row - 1))->getAlignment()
                ->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('D11:M'.($row - 1))->getAlignment()
                ->setVertical(Alignment::VERTICAL_TOP);
            foreach ($plan->destinations as $index => $destination) {
                $groupCount = max(1, $destination->recipientGroups->count());
                $sheet->getRowDimension(11 + $index)->setRowHeight(max(22, $groupCount * 15));
            }
        }

        $this->addGroupDetailSheet($spreadsheet, $plan);
        $this->addGroupRecapSheet($spreadsheet, $plan);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function addGroupDetailSheet(Spreadsheet $spreadsheet, FieldDistributionPlan $plan): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Rincian Kelompok');
        $sheet->fromArray([[
            'No', 'Jenis', 'Kode Tujuan', 'Tujuan', 'Rute', 'Kode Kelompok', 'Kelompok',
            'Ukuran Porsi', 'Terdaftar', 'Terkonfirmasi', 'Porsi Kecil', 'Porsi Besar',
            'Total Porsi', 'Catatan Kelompok',
        ]], null, 'A1');

        $row = 2;
        foreach ($plan->destinations as $destinationIndex => $destination) {
            foreach ($destination->recipientGroups as $group) {
                $sheet->fromArray([[
                    $destinationIndex + 1,
                    $destination->destination_type,
                    $destination->destination_code_snapshot,
                    $destination->destination_name_snapshot,
                    $destination->route_name,
                    $group->beneficiary_category_code_snapshot,
                    FieldDistributionPlanGroupExport::name($group),
                    FieldDistributionPlanGroupExport::portionLabel($group),
                    $group->registered_beneficiaries,
                    $group->confirmed_beneficiaries,
                    $group->small_portions,
                    $group->large_portions,
                    $group->total_portions,
                    $group->notes,
                ]], null, "A{$row}");
                $row++;
            }
        }

        $this->formatTabularSheet($sheet, 'A1:N'.max(1, $row - 1), 'A2');
        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('N')->setWidth(38);
    }

    private function addGroupRecapSheet(Spreadsheet $spreadsheet, FieldDistributionPlan $plan): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Rekap Kelompok');
        $sheet->fromArray([[
            'No', 'Kode Kelompok', 'Kelompok', 'Ukuran Porsi', 'Terdaftar', 'Terkonfirmasi',
            'Porsi Kecil', 'Porsi Besar', 'Total Porsi',
        ]], null, 'A1');

        $row = 2;
        foreach (FieldDistributionPlanGroupExport::totalsByGroup($plan) as $index => $groupTotal) {
            $sheet->fromArray([[
                $index + 1,
                $groupTotal['code'],
                $groupTotal['name'],
                $groupTotal['portion_label'],
                $groupTotal['registered'],
                $groupTotal['confirmed'],
                $groupTotal['small'],
                $groupTotal['large'],
                $groupTotal['total'],
            ]], null, "A{$row}");
            $row++;
        }

        $this->formatTabularSheet($sheet, 'A1:I'.max(1, $row - 1), 'A2');
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function formatTabularSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range, string $freezePane): void
    {
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->setAutoFilter($range);
        $sheet->freezePane($freezePane);
    }

    private function authorizeExport(Request $request, FieldDistributionPlan $fieldDistributionPlan): void
    {
        $this->authorizeSystemRecord($fieldDistributionPlan, 'field_planning.export');
    }

    private function filename(FieldDistributionPlan $plan): string
    {
        return FileNaming::report('rencana-distribusi', null, $plan->distribution_date, 'xlsx');
    }
}
