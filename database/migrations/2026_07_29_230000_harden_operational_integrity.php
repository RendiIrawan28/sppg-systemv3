<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('waste_handover_reports')) {
            return;
        }

        // Jangan menghapus atau menggabungkan dokumen aktif secara otomatis. Jika data
        // lama memiliki dua BA untuk sumber yang sama, migrasi dihentikan agar operator
        // dapat memilih dokumen yang benar tanpa kehilangan item atau histori.
        $duplicates = DB::table('waste_handover_reports')
            ->select([
                'sppg_unit_id',
                'source_type',
                'source_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('GROUP_CONCAT(id ORDER BY id) as report_ids'),
            ])
            ->whereNull('deleted_at')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('sppg_unit_id', 'source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(fn ($row): string => sprintf(
                    'unit=%s, sumber=%s#%s, report_id=%s',
                    $row->sppg_unit_id,
                    $row->source_type,
                    $row->source_id,
                    $row->report_ids,
                ))
                ->implode('; ');

            throw new RuntimeException(
                'Ditemukan Berita Acara Limbah aktif ganda. Tentukan satu dokumen yang benar sebelum migrasi dilanjutkan: '.$details
            );
        }

        // Dokumen soft-delete dipertahankan sebagai arsip, tetapi ikatan sumbernya
        // dilepas agar sumber tersebut dapat memiliki dokumen pengganti yang aktif.
        $softDeleted = DB::table('waste_handover_reports')
            ->whereNotNull('deleted_at')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->get(['id', 'source_type', 'source_id']);

        foreach ($softDeleted as $report) {
            $sourceTable = match ($report->source_type) {
                'preparation_session' => 'preparation_sessions',
                'washing_session' => 'washing_sessions',
                'cleaning_session' => 'cleaning_sessions',
                default => null,
            };

            if ($sourceTable
                && Schema::hasTable($sourceTable)
                && Schema::hasColumn($sourceTable, 'waste_handover_report_id')) {
                $updates = ['waste_handover_report_id' => null];
                if ($sourceTable === 'washing_sessions'
                    && Schema::hasColumn($sourceTable, 'waste_handed_over_at')) {
                    $updates['waste_handed_over_at'] = null;
                }

                DB::table($sourceTable)
                    ->where('waste_handover_report_id', $report->id)
                    ->update($updates);
            }

            DB::table('waste_handover_reports')
                ->where('id', $report->id)
                ->update([
                    'source_type' => null,
                    'source_id' => null,
                    'source_reference' => null,
                ]);
        }

        Schema::table('waste_handover_reports', function (Blueprint $table): void {
            $table->unique(
                ['sppg_unit_id', 'source_type', 'source_id'],
                'waste_handover_source_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('waste_handover_reports')) {
            return;
        }

        Schema::table('waste_handover_reports', function (Blueprint $table): void {
            $table->dropUnique('waste_handover_source_unique');
        });
    }
};
