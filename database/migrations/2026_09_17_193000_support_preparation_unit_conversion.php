<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('preparation_session_items')) {
            return;
        }

        $addProcessedUnit = ! Schema::hasColumn('preparation_session_items', 'processed_unit_snapshot');
        $addWasteUnit = ! Schema::hasColumn('preparation_session_items', 'waste_unit_snapshot');

        if ($addProcessedUnit || $addWasteUnit) {
            Schema::table('preparation_session_items', function (Blueprint $table) use ($addProcessedUnit, $addWasteUnit): void {
                if ($addProcessedUnit) {
                    $table->string('processed_unit_snapshot', 80)->nullable()->after('processed_quantity');
                }
                if ($addWasteUnit) {
                    $table->string('waste_unit_snapshot', 80)->nullable()->after('waste_quantity');
                }
            });
        }

        // Data lama tetap mempertahankan arti angka lama: hasil/limbah dianggap memakai satuan sumber.
        DB::table('preparation_session_items')
            ->whereNull('processed_unit_snapshot')
            ->update(['processed_unit_snapshot' => DB::raw('unit_snapshot')]);
        DB::table('preparation_session_items')
            ->whereNull('waste_unit_snapshot')
            ->update(['waste_unit_snapshot' => DB::raw('unit_snapshot')]);

        // Master stok Nogotirto sudah menyatakan 1 papan TEMPE A-ZAKI = 500 g.
        // Backfill hanya bila konversi bahan belum pernah diatur, sehingga nilai manual tidak ditimpa.
        if (Schema::hasTable('ingredients') && Schema::hasColumn('ingredients', 'grams_per_unit')) {
            DB::table('ingredients')
                ->where('code', 'B-083')
                ->where(function ($query): void {
                    $query->whereNull('grams_per_unit')->orWhere('grams_per_unit', '<=', 0);
                })
                ->update(['grams_per_unit' => 500]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('preparation_session_items')) {
            return;
        }

        $dropProcessedUnit = Schema::hasColumn('preparation_session_items', 'processed_unit_snapshot');
        $dropWasteUnit = Schema::hasColumn('preparation_session_items', 'waste_unit_snapshot');

        if ($dropProcessedUnit || $dropWasteUnit) {
            Schema::table('preparation_session_items', function (Blueprint $table) use ($dropProcessedUnit, $dropWasteUnit): void {
                if ($dropProcessedUnit) {
                    $table->dropColumn('processed_unit_snapshot');
                }
                if ($dropWasteUnit) {
                    $table->dropColumn('waste_unit_snapshot');
                }
            });
        }
    }
};
