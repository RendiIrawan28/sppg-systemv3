<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\WasteDivision;
use App\Models\SppgUnit;
use App\Models\WasteHandoverReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class PreparationWasteLegacyImporter
{
    public function import(string $path, SppgUnit $unit): array
    {
        $spreadsheet = IOFactory::load($path);
        $batchId = (string) Str::uuid();
        $summary = ['sheets' => 0, 'reports' => 0, 'items' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheetName = $sheet->getTitle();

            if (! preg_match('/^PERSIAPAN_LIMBAH_\d{4}-\d{2}-\d{2}$/i', $sheetName)) {
                continue;
            }

            $summary['sheets']++;

            try {
                $result = $this->importSheet($sheet->toArray(null, true, true, false), $sheetName, $unit, $batchId);
                $summary['reports'] += $result['reports'];
                $summary['items'] += $result['items'];
                $summary['skipped'] += $result['skipped'];
            } catch (Throwable $exception) {
                $summary['errors'][] = $sheetName . ': ' . $exception->getMessage();
            }
        }

        return $summary;
    }

    private function importSheet(array $rows, string $sheetName, SppgUnit $unit, string $batchId): array
    {
        if (count($rows) < 2) {
            return ['reports' => 0, 'items' => 0, 'skipped' => 0];
        }

        $headers = collect($rows[0])
            ->map(fn ($value): string => $this->normalizeHeader((string) $value))
            ->all();

        $groups = [];
        $skipped = 0;

        foreach (array_slice($rows, 1) as $index => $rawRow) {
            $rawRow = array_pad($rawRow, count($headers), null);
            $data = array_combine($headers, array_slice($rawRow, 0, count($headers)));

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $legacyId = trim((string) ($data['id'] ?? ''));
            $reportNumber = trim((string) ($data['no_ba'] ?? ''));
            $wasteType = trim((string) ($data['jenis_limbah'] ?? ''));

            if ($reportNumber === '' || $wasteType === '') {
                $skipped++;
                continue;
            }

            $groupKey = $reportNumber;
            $groups[$groupKey]['header'] ??= $data;
            $groups[$groupKey]['items'][] = ['data' => $data, 'legacy_id' => $legacyId ?: (string) ($index + 2)];
        }

        $reportCount = 0;
        $itemCount = 0;

        foreach ($groups as $reportNumber => $group) {
            DB::transaction(function () use (
                $unit,
                $sheetName,
                $batchId,
                $reportNumber,
                $group,
                &$reportCount,
                &$itemCount,
            ): void {
                $header = $group['header'];
                $reportDate = $this->normalizeDate($header['tanggal'] ?? null)
                    ?: $this->dateFromSheetName($sheetName)
                    ?: now()->toDateString();

                $legacyReportId = $reportNumber;

                $report = WasteHandoverReport::query()->updateOrCreate(
                    [
                        'sppg_unit_id' => $unit->getKey(),
                        'division_type' => WasteDivision::Preparation->value,
                        'legacy_sheet_name' => $sheetName,
                        'legacy_id' => $legacyReportId,
                    ],
                    [
                        'report_number' => $reportNumber,
                        'report_date' => $reportDate,
                        'first_party_name' => trim((string) ($header['nama_pihak_pertama'] ?? '-')),
                        'first_party_position' => $this->nullableString($header['jabatan_pihak_pertama'] ?? null),
                        'first_party_address' => $this->nullableString($header['alamat_pihak_pertama'] ?? null),
                        'second_party_name' => trim((string) ($header['nama_pihak_kedua'] ?? '-')),
                        'second_party_position' => $this->nullableString($header['jabatan_pihak_kedua'] ?? null),
                        'second_party_address' => $this->nullableString($header['alamat_pihak_kedua'] ?? null),
                        'notes' => $this->nullableString($header['catatan'] ?? null),
                        'petugas_name_snapshot' => $this->nullableString($header['nama_petugas'] ?? null),
                        'status' => OperationalReportStatus::Verified->value,
                        'source_system' => 'google_sheets',
                        'legacy_created_at' => $this->normalizeDateTime($header['created_at'] ?? null),
                        'import_batch_id' => $batchId,
                    ]
                );

                $reportCount++;

                foreach ($group['items'] as $index => $itemRow) {
                    $data = $itemRow['data'];

                    $report->items()->updateOrCreate(
                        ['legacy_id' => $itemRow['legacy_id']],
                        [
                            'waste_type' => trim((string) ($data['jenis_limbah'] ?? 'Limbah')),
                            'weight_kg' => $this->normalizeNumber($data['berat_limbah_kg'] ?? 0),
                            'notes' => $this->nullableString($data['catatan'] ?? null),
                            'legacy_photo_url' => $this->nullableString($data['foto_url'] ?? null),
                            'sort_order' => $index + 1,
                        ]
                    );

                    $itemCount++;
                }
            });
        }

        return ['reports' => $reportCount, 'items' => $itemCount, 'skipped' => $skipped];
    }

    private function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn ($value): bool => blank($value));
    }

    private function dateFromSheetName(string $sheetName): ?string
    {
        return preg_match('/(\d{4}-\d{2}-\d{2})$/', $sheetName, $matches)
            ? $matches[1]
            : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
            }

            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeNumber(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['.', ','], ['', '.'], trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' || $string === '-' ? null : $string;
    }
}
