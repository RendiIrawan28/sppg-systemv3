<?php

namespace Database\Seeders;

use App\Services\TKPI\TkpiImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TkpiFoodSeeder extends Seeder
{
    public function run(): void
    {
        $configured = (array) config('tkpi.seed_units', []);
        $query = DB::table('sppg_units');

        if ($configured !== []) {
            $numeric = array_values(array_filter($configured, fn ($value) => ctype_digit((string) $value)));
            $codes = array_values(array_diff($configured, $numeric));
            $query->where(function ($sub) use ($numeric, $codes): void {
                if ($numeric !== []) {
                    $sub->whereIn('id', array_map('intval', $numeric));
                }
                if ($codes !== []) {
                    $method = $numeric !== [] ? 'orWhereIn' : 'whereIn';
                    $sub->{$method}('code', $codes);
                }
            });
        } else {
            $query->where('is_active', true);
        }

        $units = $query->orderBy('id')->get();
        if ($units->isEmpty()) {
            throw new RuntimeException('Tidak ada Unit SPPG untuk import TKPI.');
        }

        /** @var TkpiImporter $importer */
        $importer = app(TkpiImporter::class);

        foreach ($units as $unit) {
            $stats = $importer->import(
                unitId: (int) $unit->id,
                mode: 'all',
                updateExisting: true,
                dryRun: false,
                missingBddFallback: config('tkpi.missing_bdd_fallback', 100.0),
            );

            $this->command?->info(sprintf(
                '%s: %d dibuat, %d diperbarui, %d nilai gizi.',
                $unit->code ?? $unit->id,
                $stats['foods_created'],
                $stats['foods_updated'],
                $stats['nutrition_written'],
            ));
        }
    }
}
