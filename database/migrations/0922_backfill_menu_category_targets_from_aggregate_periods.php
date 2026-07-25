<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beneficiary_period_category_totals')
            || ! Schema::hasTable('menu_beneficiary_category')
            || ! Schema::hasTable('menu_cycle_days')
            || ! Schema::hasTable('menu_cycles')) {
            return;
        }

        $targets = DB::table('menu_cycle_days as days')
            ->join('menu_cycles as cycles', 'cycles.id', '=', 'days.menu_cycle_id')
            ->join('beneficiary_period_category_totals as totals', function ($join): void {
                $join->on('totals.beneficiary_period_id', '=', 'cycles.beneficiary_period_id')
                    ->where('totals.total_beneficiaries', '>', 0)
                    ->whereNotNull('totals.beneficiary_category_id');
            })
            ->whereNotNull('days.menu_id')
            ->select('days.menu_id', 'totals.beneficiary_category_id')
            ->distinct()
            ->get();

        foreach ($targets as $target) {
            $exists = DB::table('menu_beneficiary_category')
                ->where('menu_id', $target->menu_id)
                ->where('beneficiary_category_id', $target->beneficiary_category_id)
                ->exists();

            if (! $exists) {
                DB::table('menu_beneficiary_category')->insert([
                    'menu_id' => $target->menu_id,
                    'beneficiary_category_id' => $target->beneficiary_category_id,
                    'portion_multiplier' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data target kategori tidak dihapus agar perubahan manual pengguna tetap aman.
    }
};
