<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodMember;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BeneficiaryPeriodExportController extends Controller
{
    public function pdf(BeneficiaryPeriod $beneficiaryPeriod): Response
    {
        $this->authorizeExport($beneficiaryPeriod);
        $this->loadPdfSummary($beneficiaryPeriod);

        return Pdf::loadView('reports.beneficiary-period-bgn-pdf', [
            'period' => $beneficiaryPeriod,
            'recap' => $this->recapForPdf($beneficiaryPeriod),
        ])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($beneficiaryPeriod, 'pdf'));
    }

    public function excel(BeneficiaryPeriod $beneficiaryPeriod): BinaryFileResponse
    {
        $this->authorizeExport($beneficiaryPeriod);
        $this->loadExcelData($beneficiaryPeriod);
        $recap = $beneficiaryPeriod->categoryTotals->isNotEmpty()
            ? $this->recapFromAggregate($beneficiaryPeriod)
            : $this->recapFromLoadedMembers($beneficiaryPeriod);
        $spreadsheet = new Spreadsheet;

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $summary->fromArray([
            ['LAPORAN MASTER PENERIMA MANFAAT - BGN'],
            ['Unit SPPG', $beneficiaryPeriod->sppgUnit?->name],
            ['Nomor Dokumen', $beneficiaryPeriod->document_number],
            ['Periode', $beneficiaryPeriod->start_date?->format('d-m-Y').' s.d. '.$beneficiaryPeriod->end_date?->format('d-m-Y')],
            ['Status', $beneficiaryPeriod->statusLabel()],
            ['Jumlah Instansi', $beneficiaryPeriod->destination_count],
            ['Jumlah Penerima Aktif', $beneficiaryPeriod->active_members],
            ['Porsi Kecil', $recap['totals']['small']],
            ['Porsi Besar', $recap['totals']['large']],
            [],
            ['Kelompok Penerima', 'Jumlah'],
        ], null, 'A1');
        $row = 12;
        foreach ($recap['groups'] as $group => $count) {
            $summary->fromArray([[$group ?: 'Tanpa Kelompok', $count]], null, "A{$row}");
            $row++;
        }
        $row += 1;
        $summary->fromArray([['Kelompok Menu', 'Porsi Kecil', 'Porsi Besar', 'Total']], null, "A{$row}");
        $menuHeaderRow = $row;
        $row++;
        foreach ($recap['menus'] as $menu => $values) {
            $summary->fromArray([[
                $this->menuLabel((string) $menu),
                $values['small'],
                $values['large'],
                $values['total'],
            ]], null, "A{$row}");
            $row++;
        }
        $summary->mergeCells('A1:F1');
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summary->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getStyle('A11:D'.max(11, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $summary->getStyle("A{$menuHeaderRow}:D{$menuHeaderRow}")->getFont()->setBold(true);

        $destinationSheet = $spreadsheet->createSheet();
        $destinationSheet->setTitle('Rekap Instansi');
        $destinationSheet->fromArray([['No', 'Jenis', 'Kode', 'Instansi', 'Kelompok', 'Porsi Kecil', 'Porsi Besar', 'Total']], null, 'A1');
        $row = 2;
        foreach ($recap['destinations'] as $index => $destination) {
            $destinationSheet->fromArray([[
                $index + 1,
                $destination['type'],
                $destination['code'],
                $destination['name'],
                collect($destination['groups'])->map(fn ($count, $name) => "{$name}: {$count}")->implode('; '),
                $destination['small'],
                $destination['large'],
                $destination['total'],
            ]], null, "A{$row}");
            $row++;
        }
        $destinationSheet->getStyle('A1:H'.max(1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $destinationSheet->getStyle('A1:H1')->getFont()->setBold(true);
        $destinationSheet->freezePane('A2');

        $memberSheet = $spreadsheet->createSheet();
        $memberSheet->setTitle($beneficiaryPeriod->categoryTotals->isNotEmpty() ? 'Jumlah Kategori' : 'Daftar Individu');
        if ($beneficiaryPeriod->categoryTotals->isNotEmpty()) {
            $memberSheet->fromArray([['No', 'Instansi', 'Kelompok Penerima', 'Kategori Porsi', 'Kelompok Menu', 'Jumlah']], null, 'A1');
            $row = 2;
            foreach ($beneficiaryPeriod->destinations as $destination) {
                foreach ($destination->categoryTotals as $total) {
                    $memberSheet->fromArray([[
                        $row - 1,
                        $destination->destination_name_snapshot,
                        $total->beneficiary_category_name_snapshot,
                        $total->portion_category === 'large' ? 'Besar' : 'Kecil',
                        $this->menuLabel((string) $total->menu_audience),
                        $total->total_beneficiaries,
                    ]], null, "A{$row}");
                    $row++;
                }
            }
            $memberSheet->getStyle('A1:F'.max(1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $memberSheet->getStyle('A1:F1')->getFont()->setBold(true);
            $memberSheet->freezePane('A2');
        } else {
            $memberSheet->fromArray([[
                'No', 'Instansi', 'NISN/NIK', 'Nama', 'Tanggal Lahir', 'Jenis Kelamin', 'Orang Tua/Wali',
                'Jenjang', 'Kelas/Golongan', 'Kelompok Penerima', 'Kategori Porsi', 'Kelompok Menu',
                'Alamat', 'Alergi', 'Kebutuhan Khusus', 'Status',
            ]], null, 'A1');
            $row = 2;
            foreach ($beneficiaryPeriod->members->sortBy('name')->values() as $index => $member) {
                $memberSheet->fromArray([[
                    $index + 1,
                    $member->destination?->destination_name_snapshot,
                    $member->identity_number,
                    $member->name,
                    $member->birth_date?->format('d-m-Y'),
                    $member->gender,
                    $member->parent_name,
                    $member->education_level,
                    $member->class_group,
                    $member->beneficiary_category_name_snapshot,
                    $member->portion_category === 'large' ? 'Besar' : 'Kecil',
                    $member->menu_audience,
                    $member->address,
                    $member->allergy_notes,
                    $member->special_needs,
                    $member->is_active ? 'Aktif' : 'Tidak Aktif',
                ]], null, "A{$row}");
                $row++;
            }
            $memberSheet->getStyle('A1:P'.max(1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $memberSheet->getStyle('A1:P1')->getFont()->setBold(true);
            $memberSheet->freezePane('A2');
        }

        $historySheet = $spreadsheet->createSheet();
        $historySheet->setTitle('Histori');
        $historySheet->fromArray([['Waktu', 'Pengguna', 'Aksi', 'Dari', 'Ke', 'Catatan']], null, 'A1');
        $row = 2;
        foreach ($beneficiaryPeriod->histories as $history) {
            $historySheet->fromArray([[
                $history->created_at?->format('d-m-Y H:i'),
                $history->user?->name,
                $history->action,
                $history->from_status,
                $history->to_status,
                $history->notes,
            ]], null, "A{$row}");
            $row++;
        }
        $historySheet->getStyle('A1:F'.max(1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $historySheet->getStyle('A1:F1')->getFont()->setBold(true);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'beneficiary-period-');
        (new Xlsx($spreadsheet))->save($temporaryFile);

        return response()->download($temporaryFile, $this->filename($beneficiaryPeriod, 'xlsx'))->deleteFileAfterSend(true);
    }

    private function authorizeExport(BeneficiaryPeriod $period): void
    {
        $this->authorizeSystemRecord($period, 'beneficiary_periods.export');

        abort_unless(
            in_array($period->status, ['approved', 'active', 'closed'], true),
            422,
            'Laporan resmi hanya tersedia setelah master periode disetujui dan dikunci.'
        );
    }

    /**
     * PDF hanya memerlukan data ringkasan. Daftar individu sengaja tidak dimuat
     * agar DomPDF tidak menghabiskan memori saat periode berisi ribuan penerima.
     */
    private function loadPdfSummary(BeneficiaryPeriod $period): void
    {
        $period->loadMissing([
            'sppgUnit',
            'approver',
            'histories.user',
            'destinations' => fn ($query) => $query->where('is_active', true)->with('categoryTotals'),
            'categoryTotals',
        ]);
    }

    /**
     * Excel tetap berisi seluruh daftar individu.
     */
    private function loadExcelData(BeneficiaryPeriod $period): void
    {
        $period->load([
            'sppgUnit',
            'creator',
            'submitter',
            'approver',
            'destinations' => fn ($query) => $query->where('is_active', true)->with(['members', 'categoryTotals']),
            'categoryTotals',
            'members.destination',
            'histories.user',
        ]);
    }

    /**
     * Rekap PDF dihitung langsung oleh database sehingga tidak perlu membuat
     * ribuan object Eloquent di memori.
     */
    private function recapForPdf(BeneficiaryPeriod $period): array
    {
        if ($period->categoryTotals->isNotEmpty()) {
            return $this->recapFromAggregate($period);
        }

        $groups = BeneficiaryPeriodMember::query()
            ->where('beneficiary_period_id', $period->id)
            ->where('is_active', true)
            ->select('beneficiary_category_name_snapshot')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('beneficiary_category_name_snapshot')
            ->orderBy('beneficiary_category_name_snapshot')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                filled($row->beneficiary_category_name_snapshot)
                    ? $row->beneficiary_category_name_snapshot
                    : 'Tanpa Kelompok' => (int) $row->total,
            ]);

        $menus = BeneficiaryPeriodMember::query()
            ->where('beneficiary_period_id', $period->id)
            ->where('is_active', true)
            ->select('menu_audience')
            ->selectRaw("SUM(CASE WHEN portion_category = 'small' THEN 1 ELSE 0 END) as small_total")
            ->selectRaw("SUM(CASE WHEN portion_category = 'large' THEN 1 ELSE 0 END) as large_total")
            ->selectRaw('COUNT(*) as grand_total')
            ->groupBy('menu_audience')
            ->orderBy('menu_audience')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) ($row->menu_audience ?: 'other') => [
                    'small' => (int) $row->small_total,
                    'large' => (int) $row->large_total,
                    'total' => (int) $row->grand_total,
                ],
            ]);

        $destinationGroups = DB::table('beneficiary_period_members')
            ->where('beneficiary_period_id', $period->id)
            ->where('is_active', true)
            ->select('beneficiary_period_destination_id', 'beneficiary_category_name_snapshot')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('beneficiary_period_destination_id', 'beneficiary_category_name_snapshot')
            ->get()
            ->groupBy('beneficiary_period_destination_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn ($row): array => [
                    filled($row->beneficiary_category_name_snapshot)
                        ? $row->beneficiary_category_name_snapshot
                        : 'Tanpa Kelompok' => (int) $row->total,
                ])
                ->sortKeys()
                ->all());

        $destinations = DB::table('beneficiary_period_destinations as d')
            ->leftJoin('beneficiary_period_members as m', function ($join): void {
                $join->on('m.beneficiary_period_destination_id', '=', 'd.id')
                    ->where('m.is_active', '=', 1);
            })
            ->where('d.beneficiary_period_id', $period->id)
            ->select([
                'd.id',
                'd.destination_type',
                'd.destination_code_snapshot',
                'd.destination_name_snapshot',
                'd.sort_order',
            ])
            ->selectRaw("SUM(CASE WHEN m.portion_category = 'small' THEN 1 ELSE 0 END) as small_total")
            ->selectRaw("SUM(CASE WHEN m.portion_category = 'large' THEN 1 ELSE 0 END) as large_total")
            ->selectRaw('COUNT(m.id) as grand_total')
            ->groupBy([
                'd.id',
                'd.destination_type',
                'd.destination_code_snapshot',
                'd.destination_name_snapshot',
                'd.sort_order',
            ])
            ->orderBy('d.sort_order')
            ->orderBy('d.destination_name_snapshot')
            ->get()
            ->map(fn ($destination): array => [
                'type' => $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu',
                'code' => $destination->destination_code_snapshot,
                'name' => $destination->destination_name_snapshot,
                'groups' => $destinationGroups->get($destination->id, []),
                'small' => (int) $destination->small_total,
                'large' => (int) $destination->large_total,
                'total' => (int) $destination->grand_total,
            ]);

        return [
            'totals' => [
                'small' => (int) $menus->sum('small'),
                'large' => (int) $menus->sum('large'),
                'total' => (int) $menus->sum('total'),
            ],
            'groups' => $groups,
            'menus' => $menus,
            'destinations' => $destinations,
        ];
    }

    private function recapFromLoadedMembers(BeneficiaryPeriod $period): array
    {
        $active = $period->members->where('is_active', true);

        $menus = $active->groupBy('menu_audience')->map(fn ($items): array => [
            'small' => $items->where('portion_category', 'small')->count(),
            'large' => $items->where('portion_category', 'large')->count(),
            'total' => $items->count(),
        ])->sortKeys();

        return [
            'groups' => $active->groupBy('beneficiary_category_name_snapshot')->map->count()->sortKeys(),
            'menus' => $menus,
            'totals' => [
                'small' => (int) $menus->sum('small'),
                'large' => (int) $menus->sum('large'),
                'total' => (int) $menus->sum('total'),
            ],
            'destinations' => $period->destinations->map(function ($destination): array {
                $members = $destination->members->where('is_active', true);

                return [
                    'type' => $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu',
                    'code' => $destination->destination_code_snapshot,
                    'name' => $destination->destination_name_snapshot,
                    'groups' => $members->groupBy('beneficiary_category_name_snapshot')->map->count()->sortKeys()->all(),
                    'small' => $members->where('portion_category', 'small')->count(),
                    'large' => $members->where('portion_category', 'large')->count(),
                    'total' => $members->count(),
                ];
            }),
        ];
    }

    private function recapFromAggregate(BeneficiaryPeriod $period): array
    {
        $totals = $period->categoryTotals;
        $menus = $totals->groupBy('menu_audience')->map(fn ($items): array => [
            'small' => (int) $items->where('portion_category', 'small')->sum('total_beneficiaries'),
            'large' => (int) $items->where('portion_category', 'large')->sum('total_beneficiaries'),
            'total' => (int) $items->sum('total_beneficiaries'),
        ])->sortKeys();

        return [
            'groups' => $totals
                ->groupBy('beneficiary_category_name_snapshot')
                ->map(fn ($items): int => (int) $items->sum('total_beneficiaries'))
                ->sortKeys(),
            'menus' => $menus,
            'totals' => [
                'small' => (int) $menus->sum('small'),
                'large' => (int) $menus->sum('large'),
                'total' => (int) $menus->sum('total'),
            ],
            'destinations' => $period->destinations->map(function ($destination): array {
                $totals = $destination->categoryTotals;

                return [
                    'type' => $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu',
                    'code' => $destination->destination_code_snapshot,
                    'name' => $destination->destination_name_snapshot,
                    'groups' => $totals
                        ->groupBy('beneficiary_category_name_snapshot')
                        ->map(fn ($items): int => (int) $items->sum('total_beneficiaries'))
                        ->sortKeys()
                        ->all(),
                    'small' => (int) $totals->where('portion_category', 'small')->sum('total_beneficiaries'),
                    'large' => (int) $totals->where('portion_category', 'large')->sum('total_beneficiaries'),
                    'total' => (int) $totals->sum('total_beneficiaries'),
                ];
            }),
        ];
    }

    private function menuLabel(string $menu): string
    {
        return match ($menu) {
            'student' => 'Siswa',
            'toddler' => 'Balita',
            'maternal' => 'Ibu Hamil / Ibu Menyusui',
            default => str($menu)->replace('_', ' ')->title()->toString(),
        };
    }

    private function filename(BeneficiaryPeriod $period, string $extension): string
    {
        $number = $period->document_number ?: $period->code;

        return 'master-penerima-bgn-'.str_replace(['/', '\\', ' '], '-', strtolower($number)).'.'.$extension;
    }
}
