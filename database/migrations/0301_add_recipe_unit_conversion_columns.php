<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ingredients') && ! Schema::hasColumn('ingredients', 'grams_per_unit')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->decimal('grams_per_unit', 14, 4)->nullable()->after('edible_portion_percent');
            });
        }

        if (Schema::hasTable('recipe_ingredients')) {
            Schema::table('recipe_ingredients', function (Blueprint $table): void {
                if (! Schema::hasColumn('recipe_ingredients', 'input_unit_code_snapshot')) {
                    $table->string('input_unit_code_snapshot', 80)->nullable()->after('measurement_unit_id');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'input_unit_name_snapshot')) {
                    $table->string('input_unit_name_snapshot', 120)->nullable()->after('input_unit_code_snapshot');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'grams_per_unit_snapshot')) {
                    $table->decimal('grams_per_unit_snapshot', 14, 4)->nullable()->after('input_unit_name_snapshot');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'input_quantity_small')) {
                    $table->decimal('input_quantity_small', 14, 4)->nullable()->after('grams_per_unit_snapshot');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'input_quantity_large')) {
                    $table->decimal('input_quantity_large', 14, 4)->nullable()->after('input_quantity_small');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'input_quantity_toddler')) {
                    $table->decimal('input_quantity_toddler', 14, 4)->nullable()->after('input_quantity_large');
                }

                if (! Schema::hasColumn('recipe_ingredients', 'input_quantity_maternal')) {
                    $table->decimal('input_quantity_maternal', 14, 4)->nullable()->after('input_quantity_toddler');
                }
            });

            $gramUnitId = DB::table('measurement_units')
                ->whereIn('code', ['g', 'gram'])
                ->value('id');

            if ($gramUnitId) {
                DB::table('recipe_ingredients')
                    ->whereNull('measurement_unit_id')
                    ->update(['measurement_unit_id' => $gramUnitId]);
            }

            DB::statement("\n                UPDATE recipe_ingredients ri\n                LEFT JOIN measurement_units mu ON mu.id = ri.measurement_unit_id\n                SET\n                    ri.input_unit_code_snapshot = COALESCE(ri.input_unit_code_snapshot, mu.code, 'g'),\n                    ri.input_unit_name_snapshot = COALESCE(ri.input_unit_name_snapshot, CONCAT(COALESCE(mu.name, 'Gram'), CASE WHEN mu.symbol IS NULL OR mu.symbol = '' THEN '' ELSE CONCAT(' (', mu.symbol, ')') END)),\n                    ri.grams_per_unit_snapshot = COALESCE(ri.grams_per_unit_snapshot, mu.to_base_factor, 1),\n                    ri.input_quantity_small = COALESCE(ri.input_quantity_small, CASE WHEN COALESCE(mu.to_base_factor, 1) > 0 THEN ri.quantity_small_grams / COALESCE(mu.to_base_factor, 1) ELSE ri.quantity_small_grams END),\n                    ri.input_quantity_large = COALESCE(ri.input_quantity_large, CASE WHEN COALESCE(mu.to_base_factor, 1) > 0 THEN ri.quantity_large_grams / COALESCE(mu.to_base_factor, 1) ELSE ri.quantity_large_grams END),\n                    ri.input_quantity_toddler = COALESCE(ri.input_quantity_toddler, CASE WHEN COALESCE(mu.to_base_factor, 1) > 0 THEN ri.quantity_toddler_grams / COALESCE(mu.to_base_factor, 1) ELSE ri.quantity_toddler_grams END),\n                    ri.input_quantity_maternal = COALESCE(ri.input_quantity_maternal, CASE WHEN COALESCE(mu.to_base_factor, 1) > 0 THEN ri.quantity_maternal_grams / COALESCE(mu.to_base_factor, 1) ELSE ri.quantity_maternal_grams END)\n            ");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_ingredients')) {
            Schema::table('recipe_ingredients', function (Blueprint $table): void {
                foreach ([
                    'input_quantity_maternal',
                    'input_quantity_toddler',
                    'input_quantity_large',
                    'input_quantity_small',
                    'grams_per_unit_snapshot',
                    'input_unit_name_snapshot',
                    'input_unit_code_snapshot',
                ] as $column) {
                    if (Schema::hasColumn('recipe_ingredients', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ingredients') && Schema::hasColumn('ingredients', 'grams_per_unit')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->dropColumn('grams_per_unit');
            });
        }
    }
};
