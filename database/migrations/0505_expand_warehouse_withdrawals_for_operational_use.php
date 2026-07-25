<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->string('unit_snapshot', 80)->default('kg')->after('ingredient_id');
            $table->decimal('initial_quantity', 14, 4)->default(0)->after('unit_snapshot');
            $table->decimal('balance_quantity', 14, 4)->default(0)->after('initial_quantity');
            $table->string('storage_type', 30)->default('dry')->after('location_name')->index();
        });

        Schema::table('warehouse_withdrawals', function (Blueprint $table): void {
            $table->string('reference_type', 40)->nullable()->after('division_code')->index();
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type')->index();
            $table->string('reference_number_snapshot', 100)->nullable()->after('reference_id');
        });

        Schema::table('warehouse_withdrawal_items', function (Blueprint $table): void {
            $table->string('unit_snapshot', 80)->default('kg')->after('expiry_date_snapshot');
            $table->decimal('requested_quantity', 14, 4)->nullable()->after('unit_snapshot');
            $table->decimal('actual_quantity', 14, 4)->nullable()->after('requested_quantity');
            $table->decimal('pickup_temperature_celsius', 6, 2)->nullable()->after('actual_quantity');
            $table->string('photo_path')->nullable()->after('pickup_temperature_celsius');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('quantity_in', 14, 4)->nullable()->after('quantity_out_kg');
            $table->decimal('quantity_out', 14, 4)->nullable()->after('quantity_in');
        });

        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->string('unit_snapshot', 80)->default('kg')->after('inventory_lot_id');
            $table->decimal('system_quantity', 14, 4)->nullable()->after('unit_snapshot');
            $table->decimal('actual_quantity', 14, 4)->nullable()->after('system_quantity');
            $table->decimal('difference_quantity', 14, 4)->nullable()->after('actual_quantity');
        });

        DB::table('inventory_lots')->update([
            'unit_snapshot' => 'kg',
            'initial_quantity' => DB::raw('initial_quantity_kg'),
            'balance_quantity' => DB::raw('balance_quantity_kg'),
        ]);
        DB::table('warehouse_withdrawal_items')->update([
            'unit_snapshot' => 'kg',
            'requested_quantity' => DB::raw('taken_quantity_kg'),
            'actual_quantity' => DB::raw('verified_quantity_kg'),
        ]);
        DB::table('stock_movements')->update([
            'quantity_in' => DB::raw('quantity_in_kg'),
            'quantity_out' => DB::raw('quantity_out_kg'),
        ]);
        DB::table('stock_adjustments')->update([
            'unit_snapshot' => 'kg',
            'system_quantity' => DB::raw('system_quantity_kg'),
            'actual_quantity' => DB::raw('actual_quantity_kg'),
            'difference_quantity' => DB::raw('difference_quantity_kg'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', fn (Blueprint $table) => $table->dropColumn([
            'unit_snapshot', 'system_quantity', 'actual_quantity', 'difference_quantity',
        ]));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['quantity_in', 'quantity_out']));
        Schema::table('warehouse_withdrawal_items', fn (Blueprint $table) => $table->dropColumn([
            'unit_snapshot', 'requested_quantity', 'actual_quantity', 'pickup_temperature_celsius', 'photo_path',
        ]));
        Schema::table('warehouse_withdrawals', fn (Blueprint $table) => $table->dropColumn([
            'reference_type', 'reference_id', 'reference_number_snapshot',
        ]));
        Schema::table('inventory_lots', fn (Blueprint $table) => $table->dropColumn([
            'unit_snapshot', 'initial_quantity', 'balance_quantity', 'storage_type',
        ]));
    }
};
