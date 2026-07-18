<?php

namespace App\Console\Commands;

use App\Services\TKPI\TkpiImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportTkpiFoods extends Command
{
    protected $signature = 'tkpi:import
        {--unit=* : ID atau kode Unit SPPG}
        {--all-units : Import ke seluruh Unit SPPG aktif}
        {--mode=all : all, raw, atau processed}
        {--missing-bdd=100 : Fallback BDD kosong; isi skip untuk melewati}
        {--no-update : Jangan memperbarui bahan TKPI yang sudah ada}
        {--dry-run : Validasi dan hitung tanpa menyimpan}';

    protected $description = 'Mengimpor bahan dan nilai gizi TKPI 2017 ke master bahan SPPG.';

    public function handle(TkpiImporter $importer): int
    {
        try {
            $units = $this->resolveUnits();
            $fallbackOption = strtolower(trim((string) $this->option('missing-bdd')));
            $fallback = $fallbackOption === 'skip' ? null : (float) $fallbackOption;

            if ($fallback !== null && ($fallback < 0 || $fallback > 100)) {
                throw new RuntimeException('Fallback BDD harus 0–100 atau skip.');
            }

            foreach ($units as $unit) {
                $this->newLine();
                $this->info("Import TKPI untuk {$unit->code} — {$unit->name}");

                $stats = $importer->import(
                    unitId: (int) $unit->id,
                    mode: (string) $this->option('mode'),
                    updateExisting: ! (bool) $this->option('no-update'),
                    dryRun: (bool) $this->option('dry-run'),
                    missingBddFallback: $fallback,
                    progress: fn (array $stats) => $this->line("  Dibaca: {$stats['rows_read']} bahan..."),
                );

                $this->table(['Metrik', 'Jumlah'], [
                    ['Baris dibaca', $stats['rows_read']],
                    ['Bahan dibuat', $stats['foods_created']],
                    ['Bahan diperbarui', $stats['foods_updated']],
                    ['Bahan dilewati', $stats['foods_skipped']],
                    ['Nilai gizi ditulis', $stats['nutrition_written']],
                    ['BDD memakai fallback', $stats['missing_bdd_fallback']],
                ]);
            }

            $this->components->success(
                $this->option('dry-run')
                    ? 'Dry run selesai. Tidak ada data yang disimpan.'
                    : 'Import TKPI selesai.'
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function resolveUnits()
    {
        if (! Schema::hasTable('sppg_units')) {
            throw new RuntimeException('Tabel sppg_units belum tersedia.');
        }

        $query = DB::table('sppg_units');
        $columns = Schema::getColumnListing('sppg_units');

        if ((bool) $this->option('all-units')) {
            if (in_array('is_active', $columns, true)) {
                $query->where('is_active', true);
            }
            $units = $query->orderBy('id')->get();
        } else {
            $identifiers = array_values(array_filter(array_map('trim', (array) $this->option('unit'))));
            if ($identifiers === []) {
                throw new RuntimeException('Gunakan --unit=1, --unit=SPPG-001, atau --all-units.');
            }

            $numeric = array_values(array_filter($identifiers, 'ctype_digit'));
            $codes = array_values(array_diff($identifiers, $numeric));
            $query->where(function ($sub) use ($numeric, $codes, $columns): void {
                if ($numeric !== []) {
                    $sub->whereIn('id', array_map('intval', $numeric));
                }
                if ($codes !== [] && in_array('code', $columns, true)) {
                    $method = $numeric !== [] ? 'orWhereIn' : 'whereIn';
                    $sub->{$method}('code', $codes);
                }
            });
            $units = $query->orderBy('id')->get();
        }

        if ($units->isEmpty()) {
            throw new RuntimeException('Unit SPPG yang dipilih tidak ditemukan.');
        }

        return $units;
    }
}
