<?php

namespace App\Http\Controllers;

use App\Models\MenuAcceptanceEvaluation;
use App\Models\MenuCycle;
use App\Models\NutritionDailyReport;
use App\Models\NutritionRequirementPlan;
use App\Services\MenuServiceCalendarService;
use App\Services\NutritionAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NutritionExportController extends Controller
{
    public function __construct(
        private readonly NutritionAccessService $access,
    ) {}

    /**
     * Export Siklus Menu ke PDF.
     */
    public function menuCyclePdf(MenuCycle $cycle): Response
    {
        $this->access->authorizeRecord(
            $cycle,
            'nutrition.export'
        );

        /*
         * Items perlu di-load karena export menampilkan
         * komponen menu, bukan hanya nama menu.
         */
        $cycle->load([
            'sppgUnit',
            'days.menu.items',
            'creator',
            'approver',
        ]);

        /*
         * Ambil hari libur yang masuk periode siklus.
         */
        $holidays = collect();

        if ($cycle->sppgUnit) {
            $calendar = app(MenuServiceCalendarService::class);
            $holidays = $cycle->days
                ->mapWithKeys(function ($day) use ($calendar, $cycle): array {
                    if (! $day->service_date) {
                        return [];
                    }

                    $holiday = $calendar->holidayFor((int) $cycle->sppg_unit_id, $day->service_date);

                    return $holiday
                        ? [$day->service_date->format('Y-m-d') => $holiday]
                        : [];
                });
        }

        /*
         * Nama file mengikuti contoh:
         *
         * SIKLUS MENU 18-21 AGUST 2026_SPPG NOGOTIRTO.pdf
         *
         * Hari libur di awal periode tidak dimasukkan
         * ke tanggal nama file jika memang tidak memiliki menu.
         */
        $serviceDays = $cycle->days
            ->filter(function ($day) use ($holidays): bool {
                if (! $day->service_date) {
                    return false;
                }

                $dateKey = $day->service_date->format('Y-m-d');

                if ($holidays->has($dateKey)) {
                    return false;
                }

                return $day->menu !== null;
            })
            ->sortBy('service_date')
            ->values();

        $firstDate = $serviceDays->first()?->service_date
            ?? $cycle->start_date;

        $lastDate = $serviceDays->last()?->service_date
            ?? $cycle->end_date
            ?? $cycle->start_date;

        $periodLabel = $this->menuCyclePeriodLabel(
            $firstDate,
            $lastDate
        );

        /*
         * Nama unit.
         *
         * Jika master sudah bernama "SPPG NOGOTIRTO",
         * jangan menambahkan "SPPG" lagi.
         */
        $unitName = trim(
            (string) ($cycle->sppgUnit?->name ?? 'SPPG')
        );

        if (
            ! str_starts_with(
                strtoupper($unitName),
                'SPPG'
            )
        ) {
            $unitName = 'SPPG '.$unitName;
        }

        $filename = sprintf(
            'SIKLUS MENU %s_%s.pdf',
            $periodLabel,
            strtoupper($unitName)
        );

        return Pdf::loadView(
            'nutrition.exports.menu-cycle',
            [
                'cycle' => $cycle,
                'holidays' => $holidays,
            ]
        )
            ->setPaper('letter', 'landscape')
            ->download(
                $this->safeMenuCycleFilename($filename)
            );
    }

    public function requirementPdf(
        NutritionRequirementPlan $plan
    ): Response {
        $this->access->authorizeRecord(
            $plan,
            'nutrition.export'
        );

        $plan->load([
            'sppgUnit',
            'menu',
            'items',
            'creator',
            'approver',
        ]);

        return Pdf::loadView(
            'nutrition.exports.requirements',
            compact('plan')
        )
            ->setPaper('a4', 'landscape')
            ->download(
                $this->safeFilename(
                    "kebutuhan-bahan-{$plan->plan_number}.pdf"
                )
            );
    }

    public function requirementExcel(
        NutritionRequirementPlan $plan
    ): StreamedResponse {
        $this->access->authorizeRecord(
            $plan,
            'nutrition.export'
        );

        $plan->load([
            'sppgUnit',
            'menu',
            'items',
        ]);

        $spreadsheet = new Spreadsheet;

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kebutuhan Bahan');

        $sheet->fromArray([
            ['RENCANA KEBUTUHAN BAHAN'],
            ['Nomor', $plan->plan_number],
            [
                'Tanggal',
                $plan->requirement_date?->format('d-m-Y'),
            ],
            ['Menu', $plan->menu?->name],
            [
                'Jumlah Porsi Aktual',
                $plan->total_portions,
            ],
            [
                'Porsi Efektif',
                (float) $plan->effective_portions,
            ],
            [
                'Buffer (%)',
                $plan->buffer_percent,
            ],
            [
                'Total Berat (kg)',
                $plan->total_weight_kg,
            ],
            [],
            ['RINCIAN PORSI PER KELOMPOK'],
            [
                'Kelompok',
                'Kelompok Menu',
                'Kategori Porsi',
                'Porsi Aktual',
                'Pengali',
                'Porsi Efektif',
            ],
        ], null, 'A1');

        $row = 12;

        foreach (
            ($plan->portion_breakdown ?? []) as $allocation
        ) {
            $sheet->fromArray([[
                $allocation['name']
                    ?? $allocation['code']
                    ?? '-',

                strtoupper(
                    str_replace(
                        '_',
                        ' ',
                        (string) (
                            $allocation['menu_audience']
                            ?? '-'
                        )
                    )
                ),

                strtoupper(
                    (string) (
                        $allocation['portion_size']
                        ?? '-'
                    )
                ),

                (int) (
                    $allocation['actual_portions']
                    ?? 0
                ),

                (float) (
                    $allocation['portion_multiplier']
                    ?? 1
                ),

                (float) (
                    $allocation['effective_portions']
                    ?? 0
                ),
            ]], null, "A{$row}");

            $row++;
        }

        $row++;

        $itemHeaderRow = $row;

        $sheet->fromArray([[
            'No',
            'Kode',
            'Nama Bahan',
            'Hidangan',
            'Gram/Porsi Standar',
            'Porsi Efektif',
            'Kebutuhan Dasar (g)',
            'Buffer (%)',
            'Total (g)',
            'Total (kg)',
            'BDD (%)',
            'Rincian Perhitungan',
        ]], null, "A{$itemHeaderRow}");

        $row++;

        foreach (
            $plan->items as $index => $item
        ) {
            $sheet->fromArray([[
                $index + 1,
                $item->ingredient_code_snapshot,
                $item->ingredient_name_snapshot,
                $item->recipe_components,

                (float)
                    $item->quantity_per_portion_grams,

                (float)
                    $item->effective_portions,

                (float)
                    $item->base_quantity_grams,

                (float)
                    $item->buffer_percent,

                (float)
                    $item->total_quantity_grams,

                (float)
                    $item->total_quantity_kg,

                $item->edible_portion_percent !== null
                    ? (float)
                        $item->edible_portion_percent
                    : null,

                $item->calculation_breakdown_summary,
            ]], null, "A{$row}");

            $row++;
        }

        foreach (range('A', 'L') as $column) {
            $sheet
                ->getColumnDimension($column)
                ->setAutoSize(true);
        }

        $sheet
            ->getStyle('A1:L1')
            ->getFont()
            ->setBold(true)
            ->setSize(14);

        $sheet
            ->getStyle('A10:F11')
            ->getFont()
            ->setBold(true);

        $sheet
            ->getStyle(
                "A{$itemHeaderRow}:L{$itemHeaderRow}"
            )
            ->getFont()
            ->setBold(true);

        $sheet->freezePane('A12');

        return $this->xlsxResponse(
            $spreadsheet,
            $this->safeFilename(
                "kebutuhan-bahan-{$plan->plan_number}.xlsx"
            )
        );
    }

    public function evaluationPdf(
        MenuAcceptanceEvaluation $evaluation
    ): Response {
        $this->access->authorizeRecord(
            $evaluation,
            'nutrition.export'
        );

        $evaluation->load([
            'sppgUnit',
            'menu',
            'evaluator',
            'approver',
        ]);

        return Pdf::loadView(
            'nutrition.exports.evaluation',
            compact('evaluation')
        )
            ->setPaper('a4', 'portrait')
            ->download(
                $this->safeFilename(
                    'evaluasi-menu-'
                    .$evaluation->evaluation_date?->format(
                        'Ymd'
                    )
                    ."-{$evaluation->id}.pdf"
                )
            );
    }

    public function dailyReportPdf(
        NutritionDailyReport $report
    ): Response {
        $this->access->authorizeRecord(
            $report,
            'nutrition.export'
        );

        $report->load([
            'sppgUnit',
            'menu',
            'components',
            'generator',
            'approver',
        ]);

        return Pdf::loadView(
            'nutrition.exports.daily-report',
            compact('report')
        )
            ->setPaper('a4', 'landscape')
            ->download(
                $this->safeFilename(
                    "laporan-gizi-{$report->report_number}.pdf"
                )
            );
    }

    public function dailyReportExcel(
        NutritionDailyReport $report
    ): StreamedResponse {
        $this->access->authorizeRecord(
            $report,
            'nutrition.export'
        );

        $report->load([
            'sppgUnit',
            'menu',
            'components',
        ]);

        $spreadsheet = new Spreadsheet;

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');

        $summary->fromArray([
            ['LAPORAN GIZI HARIAN'],
            ['Nomor', $report->report_number],
            [
                'Tanggal',
                $report->report_date?->format('d-m-Y'),
            ],
            ['Menu', $report->menu?->name],
            [
                'Penerima Rencana',
                $report->planned_beneficiaries,
            ],
            [
                'Penerima Aktual',
                $report->actual_beneficiaries,
            ],
            [
                'Porsi Rencana',
                $report->planned_portions,
            ],
            [
                'Porsi Tersaji',
                $report->served_portions,
            ],
            [
                'Porsi Kembali',
                $report->returned_portions,
            ],
            [
                'Penerimaan (%)',
                $report->average_acceptance_percent,
            ],
            [
                'Sisa (%)',
                $report->average_waste_percent,
            ],
            [
                'Menu Khusus',
                $report->special_menu_count,
            ],
            [
                'Konflik Alergen',
                $report->allergen_conflicts_count,
            ],
            [
                'Temuan Terbuka',
                $report->open_findings_count,
            ],
            [
                'Evaluasi',
                $report->evaluation_notes,
            ],
            [
                'Rekomendasi',
                $report->recommendations,
            ],
        ], null, 'A1');

        $summary
            ->getColumnDimension('A')
            ->setWidth(26);

        $summary
            ->getColumnDimension('B')
            ->setWidth(65);

        $summary
            ->getStyle('A1:B1')
            ->getFont()
            ->setBold(true)
            ->setSize(14);

        $components = $spreadsheet->createSheet();
        $components->setTitle('Komponen Gizi');

        $components->fromArray([[
            'No',
            'Komponen',
            'Satuan',
            'Rencana/Porsi',
            'Aktual/Porsi',
            'Target/Porsi',
            'Pencapaian (%)',
            'Total Rencana',
            'Total Aktual',
        ]], null, 'A1');

        $row = 2;

        foreach (
            $report->components as $index => $component
        ) {
            $components->fromArray([[
                $index + 1,
                $component->component_name_snapshot,
                $component->unit_snapshot,

                (float)
                    $component->planned_per_portion,

                (float)
                    $component->actual_per_portion,

                $component->target_per_portion !== null
                    ? (float)
                        $component->target_per_portion
                    : null,

                $component->achievement_percent !== null
                    ? (float)
                        $component->achievement_percent
                    : null,

                (float)
                    $component->planned_total,

                (float)
                    $component->actual_total,
            ]], null, "A{$row}");

            $row++;
        }

        foreach (range('A', 'I') as $column) {
            $components
                ->getColumnDimension($column)
                ->setAutoSize(true);
        }

        $components
            ->getStyle('A1:I1')
            ->getFont()
            ->setBold(true);

        $components->freezePane('A2');

        return $this->xlsxResponse(
            $spreadsheet,
            $this->safeFilename(
                "laporan-gizi-{$report->report_number}.xlsx"
            )
        );
    }

    /**
     * Format periode nama file Siklus Menu.
     */
    private function menuCyclePeriodLabel(
        mixed $startDate,
        mixed $endDate
    ): string {
        if (! $startDate || ! $endDate) {
            return now()->format('d-m-Y');
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        /*
         * Disesuaikan dengan contoh filename:
         * AGUST, SEPT, OKT, dan seterusnya.
         */
        $months = [
            1 => 'JAN',
            2 => 'FEB',
            3 => 'MAR',
            4 => 'APR',
            5 => 'MEI',
            6 => 'JUN',
            7 => 'JUL',
            8 => 'AGUST',
            9 => 'SEPT',
            10 => 'OKT',
            11 => 'NOV',
            12 => 'DES',
        ];

        /*
         * Contoh:
         * 18-21 AGUST 2026
         */
        if (
            $start->month === $end->month
            && $start->year === $end->year
        ) {
            return sprintf(
                '%d-%d %s %d',
                $start->day,
                $end->day,
                $months[$start->month],
                $start->year
            );
        }

        /*
         * Contoh:
         * 30 AGUST-4 SEPT 2026
         */
        if ($start->year === $end->year) {
            return sprintf(
                '%d %s-%d %s %d',
                $start->day,
                $months[$start->month],
                $end->day,
                $months[$end->month],
                $start->year
            );
        }

        /*
         * Jika melewati tahun.
         */
        return sprintf(
            '%d %s %d-%d %s %d',
            $start->day,
            $months[$start->month],
            $start->year,
            $end->day,
            $months[$end->month],
            $end->year
        );
    }

    /**
     * Nama file Siklus Menu mempertahankan spasi,
     * tetapi membersihkan karakter ilegal filesystem.
     */
    private function safeMenuCycleFilename(
        string $filename
    ): string {
        $filename = trim($filename);

        $filename = preg_replace(
            '/[\\\\\/:*?"<>|]+/',
            '-',
            $filename
        );

        $filename = preg_replace(
            '/\s+/',
            ' ',
            $filename
        );

        return $filename ?: 'SIKLUS MENU.pdf';
    }

    private function xlsxResponse(
        Spreadsheet $spreadsheet,
        string $filename
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))
                    ->save('php://output');

                $spreadsheet
                    ->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    /**
     * Tetap dipakai export lain.
     */
    private function safeFilename(
        string $filename
    ): string {
        return preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            $filename
        ) ?: 'export';
    }
}
