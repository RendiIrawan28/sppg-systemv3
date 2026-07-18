<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nutrition_requirement_items')) {
            Schema::table('nutrition_requirement_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('nutrition_requirement_items', 'quantity_per_portion')) {
                    $table->decimal('quantity_per_portion', 14, 4)->nullable()->after('unit_snapshot');
                }

                if (! Schema::hasColumn('nutrition_requirement_items', 'base_quantity')) {
                    $table->decimal('base_quantity', 14, 4)->nullable()->after('effective_portions');
                }

                if (! Schema::hasColumn('nutrition_requirement_items', 'total_quantity')) {
                    $table->decimal('total_quantity', 14, 4)->nullable()->after('buffer_percent');
                }
            });

            DB::statement("\n                UPDATE nutrition_requirement_items\n                SET\n                    quantity_per_portion = COALESCE(quantity_per_portion, quantity_per_portion_grams),\n                    base_quantity = COALESCE(base_quantity, base_quantity_grams),\n                    total_quantity = COALESCE(total_quantity, total_quantity_kg),\n                    unit_snapshot = COALESCE(NULLIF(unit_snapshot, ''), 'kg')\n            ");
        }

        if (Schema::hasTable('procurement_request_items')) {
            Schema::table('procurement_request_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('procurement_request_items', 'requested_quantity')) {
                    $table->decimal('requested_quantity', 14, 4)->nullable()->after('unit_snapshot');
                }

                if (! Schema::hasColumn('procurement_request_items', 'approved_quantity')) {
                    $table->decimal('approved_quantity', 14, 4)->nullable()->after('requested_quantity');
                }
            });

            DB::statement("\n                UPDATE procurement_request_items\n                SET\n                    requested_quantity = COALESCE(requested_quantity, requested_quantity_kg),\n                    approved_quantity = COALESCE(approved_quantity, approved_quantity_kg),\n                    unit_snapshot = COALESCE(NULLIF(unit_snapshot, ''), 'kg')\n            ");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('procurement_request_items')) {
            Schema::table('procurement_request_items', function (Blueprint $table): void {
                foreach (['approved_quantity', 'requested_quantity'] as $column) {
                    if (Schema::hasColumn('procurement_request_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('nutrition_requirement_items')) {
            Schema::table('nutrition_requirement_items', function (Blueprint $table): void {
                foreach (['total_quantity', 'base_quantity', 'quantity_per_portion'] as $column) {
                    if (Schema::hasColumn('nutrition_requirement_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
