<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nutrition_standards')) {
            return;
        }

        DB::table('nutrition_standards')->update([
            'minimum_value' => DB::raw('CASE WHEN minimum_value IS NULL THEN NULL ELSE minimum_value * 100 / 30 END'),
            'target_value' => DB::raw('target_value * 100 / 30'),
            'maximum_value' => DB::raw('CASE WHEN maximum_value IS NULL THEN NULL ELSE maximum_value * 100 / 30 END'),
            'notes' => DB::raw("CONCAT(COALESCE(notes, ''), ' Basis standar dikonversi menjadi 100% kebutuhan gizi harian.')"),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('nutrition_standards')) {
            return;
        }

        DB::table('nutrition_standards')->update([
            'minimum_value' => DB::raw('CASE WHEN minimum_value IS NULL THEN NULL ELSE minimum_value * 30 / 100 END'),
            'target_value' => DB::raw('target_value * 30 / 100'),
            'maximum_value' => DB::raw('CASE WHEN maximum_value IS NULL THEN NULL ELSE maximum_value * 30 / 100 END'),
            'updated_at' => now(),
        ]);
    }
};
