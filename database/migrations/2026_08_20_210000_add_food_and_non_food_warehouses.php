<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name', 100);
                $table->string('type', 20)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->unique(['sppg_unit_id', 'code'], 'wh_unit_code_uq');
            });
        }

        if (! Schema::hasTable('non_food_items')) {
            Schema::create('non_food_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
                $table->string('code', 60);
                $table->string('name', 160);
                $table->string('category', 60)->default('Lainnya')->index();
                $table->foreignId('measurement_unit_id')->constrained()->restrictOnDelete();
                $table->decimal('minimum_stock', 14, 4)->default(0);
                $table->decimal('target_stock', 14, 4)->default(0);
                $table->string('default_location')->nullable();
                $table->boolean('tracks_lot')->default(false);
                $table->boolean('tracks_expiry')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['sppg_unit_id', 'code'], 'nfi_unit_code_uq');
                $table->index(['sppg_unit_id', 'name'], 'nfi_unit_name_ix');
            });
        }

        $this->addWarehouseColumn('procurement_requests');
        $this->addWarehouseColumn('stock_receipts');
        $this->addWarehouseColumn('inventory_lots');
        $this->addWarehouseColumn('stock_movements');
        $this->addWarehouseColumn('opening_stocks');
        $this->addWarehouseColumn('warehouse_withdrawals');
        $this->addWarehouseColumn('stock_adjustments');

        if (! Schema::hasColumn('procurement_requests', 'procurement_type')) {
            Schema::table('procurement_requests', function (Blueprint $table): void {
                $table->string('procurement_type', 20)->default('food')->index('pr_type_ix');
            });
        }

        $this->addNonFoodReference('inventory_lots');
        $this->addNonFoodReference('stock_movements');
        $this->addNonFoodReference('procurement_request_items');
        $this->addNonFoodReference('stock_receipt_items');
        $this->addNonFoodReference('opening_stock_items');
        $this->addNonFoodReference('warehouse_withdrawal_items');
        $this->addNonFoodReference('stock_adjustments');

        if (! Schema::hasColumn('nutrition_requirement_plans', 'beneficiary_period_id')) {
            Schema::table('nutrition_requirement_plans', function (Blueprint $table): void {
                $table->foreignId('beneficiary_period_id')->nullable()->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('nutrition_requirement_plans', 'menu_cycle_day_id')) {
            Schema::table('nutrition_requirement_plans', function (Blueprint $table): void {
                $table->foreignId('menu_cycle_day_id')->nullable()->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('nutrition_requirement_plans', 'source_type')) {
            Schema::table('nutrition_requirement_plans', function (Blueprint $table): void {
                $table->string('source_type', 50)->nullable()->index('nrp_source_ix');
            });
        }
        if (! $this->hasIndex('nutrition_requirement_plans', 'nrp_unit_day_ix')) {
            Schema::table('nutrition_requirement_plans', function (Blueprint $table): void {
                $table->index(['sppg_unit_id', 'menu_cycle_day_id'], 'nrp_unit_day_ix');
            });
        }

        $now = now();
        DB::table('sppg_units')->orderBy('id')->each(function (object $unit) use ($now): void {
            DB::table('warehouses')->updateOrInsert(
                ['sppg_unit_id' => $unit->id, 'code' => 'FOOD'],
                ['name' => 'Gudang Pangan', 'type' => 'food', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
            DB::table('warehouses')->updateOrInsert(
                ['sppg_unit_id' => $unit->id, 'code' => 'NON_FOOD'],
                ['name' => 'Gudang Non-Pangan', 'type' => 'non_food', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        });

        foreach (DB::table('warehouses')->where('code', 'FOOD')->get(['id', 'sppg_unit_id']) as $warehouse) {
            foreach (['procurement_requests', 'stock_receipts', 'inventory_lots', 'stock_movements', 'opening_stocks', 'warehouse_withdrawals', 'stock_adjustments'] as $table) {
                DB::table($table)
                    ->where('sppg_unit_id', $warehouse->sppg_unit_id)
                    ->whereNull('warehouse_id')
                    ->update(['warehouse_id' => $warehouse->id]);
            }
        }

        DB::table('procurement_requests')->whereNull('procurement_type')->orWhere('procurement_type', '')->update(['procurement_type' => 'food']);
        DB::table('nutrition_requirement_plans')->whereNull('source_type')->update([
            'source_type' => 'field_distribution_plan_legacy',
        ]);

        $this->makeIngredientNullable('inventory_lots');
        $this->makeIngredientNullable('stock_movements');
        $this->makeIngredientNullable('procurement_request_items');
        $this->makeIngredientNullable('stock_receipt_items');
        $this->makeIngredientNullable('opening_stock_items');
        $this->makeIngredientNullable('warehouse_withdrawal_items');
    }

    public function down(): void
    {
        // Kolom dan data sengaja dipertahankan untuk mencegah hilangnya histori stok produksi.
    }

    private function addWarehouseColumn(string $tableName): void
    {
        if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'warehouse_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    private function addNonFoodReference(string $tableName): void
    {
        if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'non_food_item_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('non_food_item_id')->nullable()->constrained('non_food_items')->nullOnDelete();
            });
        }
    }

    private function makeIngredientNullable(string $tableName): void
    {
        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'ingredient_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            });
        }
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        return collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $indexName,
        );
    }
};
