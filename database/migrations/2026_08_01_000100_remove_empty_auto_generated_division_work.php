<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('processing_batches')) {
            $processingIds = DB::table('processing_batches')
                ->whereNull('deleted_at')
                ->whereNotNull('field_distribution_plan_id')
                ->where('state', 'planned')
                ->where('status', 'draft')
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('processing_material_usages')
                    ->whereColumn('processing_material_usages.processing_batch_id', 'processing_batches.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('processing_temperature_logs')
                    ->whereColumn('processing_temperature_logs.processing_batch_id', 'processing_batches.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('processing_documentations')
                    ->whereColumn('processing_documentations.processing_batch_id', 'processing_batches.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('processing_histories')
                    ->whereColumn('processing_histories.processing_batch_id', 'processing_batches.id'))
                ->pluck('id');

            if ($processingIds->isNotEmpty()) {
                DB::table('field_distribution_plans')
                    ->whereIn('processing_batch_id', $processingIds)
                    ->update(['processing_batch_id' => null, 'updated_at' => $now]);
                DB::table('portioning_sessions')
                    ->whereIn('processing_batch_id', $processingIds)
                    ->update(['processing_batch_id' => null, 'updated_at' => $now]);
                DB::table('processing_batches')
                    ->whereIn('id', $processingIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        }

        if (Schema::hasTable('portioning_sessions')) {
            $portioningIds = DB::table('portioning_sessions')
                ->whereNull('deleted_at')
                ->whereNotNull('field_distribution_plan_id')
                ->where('state', 'planned')
                ->where('status', 'draft')
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('portioning_route_records')
                    ->whereColumn('portioning_route_records.portioning_session_id', 'portioning_sessions.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('portioning_leftover_records')
                    ->whereColumn('portioning_leftover_records.portioning_session_id', 'portioning_sessions.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('portioning_supplies')
                    ->whereColumn('portioning_supplies.portioning_session_id', 'portioning_sessions.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('portioning_histories')
                    ->whereColumn('portioning_histories.portioning_session_id', 'portioning_sessions.id'))
                ->pluck('id');

            if ($portioningIds->isNotEmpty()) {
                DB::table('field_distribution_plans')
                    ->whereIn('portioning_session_id', $portioningIds)
                    ->update(['portioning_session_id' => null, 'updated_at' => $now]);
                DB::table('distribution_runs')
                    ->whereIn('portioning_session_id', $portioningIds)
                    ->update(['portioning_session_id' => null, 'updated_at' => $now]);
                DB::table('portioning_sessions')
                    ->whereIn('id', $portioningIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Pembersihan hanya menyentuh draft kosong dan menggunakan soft delete.
        // Pemulihan, bila diperlukan, dilakukan secara eksplisit agar tidak
        // menghidupkan kembali dokumen draft yang memang sudah dibuang pengguna.
    }
};
