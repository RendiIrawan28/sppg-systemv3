<?php

namespace App\Console\Commands;

use App\Models\SppgUnit;
use App\Services\PreparationWasteLegacyImporter;
use Illuminate\Console\Command;

class ImportLegacyPreparationWaste extends Command
{
    protected $signature = 'sppg:import-preparation-waste
        {file : Path file XLSX lama}
        {--unit=SPPG-001 : Kode Unit SPPG}';

    protected $description = 'Import sheet PERSIAPAN_LIMBAH_yyyy-MM-dd ke arsip berita acara limbah';

    public function handle(PreparationWasteLegacyImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $unit = SppgUnit::query()
            ->where('code', (string) $this->option('unit'))
            ->first();

        if (! $unit) {
            $this->error('Unit SPPG tidak ditemukan.');
            return self::FAILURE;
        }

        $result = $importer->import($path, $unit);

        $this->table(
            ['Sheet', 'BA', 'Item', 'Dilewati', 'Error'],
            [[
                $result['sheets'],
                $result['reports'],
                $result['items'],
                $result['skipped'],
                count($result['errors']),
            ]]
        );

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
