<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->keepOnlyLatestResultDocumentation();

        if (Schema::hasTable('preparation_documentations')) {
            Schema::table('preparation_documentations', function (Blueprint $table): void {
                if (Schema::hasColumn('preparation_documentations', 'phase')) {
                    $table->dropIndex(['phase']);
                    $table->dropColumn('phase');
                }
                if (Schema::hasColumn('preparation_documentations', 'caption')) {
                    $table->dropColumn('caption');
                }
                if (Schema::hasColumn('preparation_documentations', 'sort_order')) {
                    $table->dropColumn('sort_order');
                }
            });

            Schema::table('preparation_documentations', function (Blueprint $table): void {
                $table->unique('preparation_session_id', 'preparation_documentation_session_unique');
            });
        }

        if (Schema::hasTable('preparation_sessions')
            && Schema::hasColumn('preparation_sessions', 'handed_over_at')) {
            Schema::table('preparation_sessions', fn (Blueprint $table) => $table->dropColumn('handed_over_at'));
        }

        if (Schema::hasTable('processing_batches')
            && Schema::hasColumn('processing_batches', 'preparation_material_handover_id')) {
            Schema::table('processing_batches', function (Blueprint $table): void {
                $index = substr('idx_'.md5('processing_batches_preparation_material_handover_id'), 0, 32);
                $table->dropIndex($index);
            });
            Schema::table('processing_batches', fn (Blueprint $table) => $table->dropColumn('preparation_material_handover_id'));
        }

        Schema::dropIfExists('preparation_handovers');
        Schema::dropIfExists('preparation_deviations');
        Schema::dropIfExists('preparation_steps');
        Schema::dropIfExists('preparation_material_handover_items');
        Schema::dropIfExists('preparation_material_handovers');
        Schema::dropIfExists('preparation_material_inspection_histories');
        Schema::dropIfExists('preparation_material_inspections');
    }

    public function down(): void
    {
        // Penghapusan alur lama bersifat permanen. Data aktif tetap berada pada
        // preparation_sessions, preparation_session_items, dan preparation_returns.
    }

    private function keepOnlyLatestResultDocumentation(): void
    {
        if (! Schema::hasTable('preparation_documentations')) {
            return;
        }

        DB::table('preparation_documentations')
            ->orderBy('preparation_session_id')
            ->orderByDesc('id')
            ->get(['id', 'preparation_session_id'])
            ->groupBy('preparation_session_id')
            ->each(function ($rows): void {
                $obsoleteIds = $rows->skip(1)->pluck('id');
                if ($obsoleteIds->isNotEmpty()) {
                    DB::table('preparation_documentations')->whereIn('id', $obsoleteIds)->delete();
                }
            });
    }
};
