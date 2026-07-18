<?php

namespace App\Console\Commands;

use App\Models\SppgUnit;
use App\Services\PreparationMaterialInspectionLegacyImporter;
use Illuminate\Console\Command;

class ImportLegacyPreparationMaterials extends Command
{
    protected $signature = 'sppg:import-preparation-materials
        {file : Path file XLSX hasil ekspor Google Sheets}
        {--unit=SPPG-001 : ID atau kode Unit SPPG}';

    protected $description = 'Import laporan PERSIAPAN_yyyy-MM-dd dari aplikasi Apps Script lama.';

    public function handle(PreparationMaterialInspectionLegacyImporter $importer): int
    {
        $filePath = realpath($this->argument('file'));

        if (! $filePath || ! is_file($filePath)) {
            $this->error('File tidak ditemukan.');
            return self::FAILURE;
        }

        $unitValue = (string) $this->option('unit');

        $unit = SppgUnit::query()
            ->where('code', $unitValue)
            ->when(is_numeric($unitValue), fn ($query) =>
                $query->orWhereKey((int) $unitValue))
            ->first();

        if (! $unit) {
            $this->error("Unit SPPG [{$unitValue}] tidak ditemukan.");
            return self::FAILURE;
        }

        $stats = $importer->import($filePath, $unit);

        $this->info('Import selesai.');
        $this->table(
            ['Batch', 'Sheet', 'Dibuat', 'Diperbarui', 'Dilewati'],
            [[
                $stats['batch_id'],
                $stats['sheets'],
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
            ]]
        );

        if ($stats['errors'] !== []) {
            $this->warn('Sebagian baris tidak berhasil diimpor:');
            $this->table(
                ['Sheet', 'Baris', 'Pesan'],
                collect($stats['errors'])
                    ->take(50)
                    ->map(fn (array $error): array => [
                        $error['sheet'],
                        $error['row'],
                        $error['message'],
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
