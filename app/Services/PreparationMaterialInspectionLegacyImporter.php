<?php

namespace App\Services;

use App\Enums\MaterialCondition;
use App\Enums\OperationalReportStatus;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\PreparationMaterialInspection;
use App\Models\SppgUnit;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class PreparationMaterialInspectionLegacyImporter
{
    public function import(string $filePath, SppgUnit $unit): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $batchId = (string) Str::uuid();

        $stats = [
            'batch_id' => $batchId,
            'sheets' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheetName = trim($sheet->getTitle());

            if (! Str::startsWith(Str::upper($sheetName), 'PERSIAPAN_')) {
                continue;
            }

            if (Str::startsWith(Str::upper($sheetName), 'PERSIAPAN_LIMBAH_')) {
                continue;
            }

            $stats['sheets']++;
            $rows = $sheet->toArray(null, true, false, false);

            if (count($rows) < 2) {
                continue;
            }

            $headers = collect($rows[0])
                ->map(fn (mixed $value): string => $this->normalizeHeader((string) $value))
                ->all();

            foreach (array_slice($rows, 1) as $index => $rawRow) {
                $excelRow = $index + 2;
                $rawRow = array_pad($rawRow, count($headers), null);
                $row = array_combine($headers, array_slice($rawRow, 0, count($headers)));

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                try {
                    $legacyId = trim((string) ($row['id'] ?? ''));
                    $materialName = trim((string) ($row['nama_bahan'] ?? ''));

                    if ($legacyId === '' || $materialName === '') {
                        $stats['skipped']++;
                        $stats['errors'][] = [
                            'sheet' => $sheetName,
                            'row' => $excelRow,
                            'message' => 'ID atau Nama Bahan kosong.',
                        ];
                        continue;
                    }

                    $reportDate = $this->normalizeDate($row['tanggal'] ?? null)
                        ?? $this->dateFromSheetName($sheetName);

                    if (! $reportDate) {
                        $stats['skipped']++;
                        $stats['errors'][] = [
                            'sheet' => $sheetName,
                            'row' => $excelRow,
                            'message' => 'Tanggal tidak dapat dibaca.',
                        ];
                        continue;
                    }

                    $quantity = $this->normalizeNumber($row['banyaknya'] ?? null);
                    $unitName = trim((string) ($row['satuan'] ?? ''));
                    $condition = $this->resolveCondition($row);
                    $petugasName = trim((string) ($row['nama_petugas'] ?? '')) ?: 'Petugas Legacy';
                    $legacyCreatedAt = $this->normalizeDateTime($row['created_at'] ?? null);

                    if ($quantity <= 0 || $unitName === '') {
                        $stats['skipped']++;
                        $stats['errors'][] = [
                            'sheet' => $sheetName,
                            'row' => $excelRow,
                            'message' => 'Banyaknya harus lebih dari nol dan satuan wajib diisi.',
                        ];
                        continue;
                    }

                    $ingredient = Ingredient::query()
                        ->where('sppg_unit_id', $unit->getKey())
                        ->whereRaw('LOWER(name) = ?', [Str::lower($materialName)])
                        ->first();

                    $measurementUnit = MeasurementUnit::query()
                        ->where(function ($query) use ($unitName): void {
                            $query
                                ->whereRaw('LOWER(code) = ?', [Str::lower($unitName)])
                                ->orWhereRaw('LOWER(symbol) = ?', [Str::lower($unitName)])
                                ->orWhereRaw('LOWER(name) = ?', [Str::lower($unitName)]);
                        })
                        ->first();

                    $inspection = PreparationMaterialInspection::query()
                        ->withTrashed()
                        ->where('sppg_unit_id', $unit->getKey())
                        ->where('source_system', 'legacy_apps_script')
                        ->where('legacy_sheet_name', $sheetName)
                        ->where('legacy_id', $legacyId)
                        ->first();

                    $values = [
                        'report_date' => $reportDate,
                        'ingredient_id' => $ingredient?->getKey(),
                        'material_name' => $materialName,
                        'quantity' => $quantity,
                        'measurement_unit_id' => $measurementUnit?->getKey(),
                        'unit_name' => $unitName,
                        'condition' => $condition,
                        'remarks' => $this->nullableString($row['keterangan'] ?? null),
                        'petugas_id' => null,
                        'petugas_name_snapshot' => $petugasName,
                        'photo_path' => null,
                        'legacy_photo_url' => $this->nullableString($row['foto_url'] ?? null),
                        'status' => OperationalReportStatus::Verified,
                        'verified_at' => $legacyCreatedAt,
                        'source_system' => 'legacy_apps_script',
                        'legacy_id' => $legacyId,
                        'legacy_sheet_name' => $sheetName,
                        'legacy_created_at' => $legacyCreatedAt,
                        'import_batch_id' => $batchId,
                    ];

                    if ($inspection) {
                        if ($inspection->trashed()) {
                            $inspection->restore();
                        }

                        $inspection->update($values);
                        $stats['updated']++;
                    } else {
                        PreparationMaterialInspection::query()->create([
                            ...$values,
                            'sppg_unit_id' => $unit->getKey(),
                        ]);
                        $stats['created']++;
                    }
                } catch (Throwable $exception) {
                    $stats['skipped']++;
                    $stats['errors'][] = [
                        'sheet' => $sheetName,
                        'row' => $excelRow,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        return $stats;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->ascii()
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn (mixed $value): bool => blank($value));
    }

    private function resolveCondition(array $row): MaterialCondition
    {
        if ($this->isTruthy($row['rusak'] ?? null)) {
            return MaterialCondition::Damaged;
        }

        if ($this->isTruthy($row['sedang'] ?? null)) {
            return MaterialCondition::Moderate;
        }

        return MaterialCondition::Good;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), [
            '1', 'true', 'ya', 'yes', 'x', 'v', '✓', 'baik', 'rusak', 'sedang',
        ], true);
    }

    private function normalizeNumber(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }

            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse(trim((string) $value))->format('Y-m-d');
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
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d H:i:s');
            }

            return Carbon::parse(trim((string) $value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function dateFromSheetName(string $sheetName): ?string
    {
        if (! preg_match('/PERSIAPAN_(\d{4}-\d{2}-\d{2})$/i', $sheetName, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
