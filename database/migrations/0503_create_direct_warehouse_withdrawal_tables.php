<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('ingredient_id')->index();
            $table->foreignId('stock_receipt_item_id')->nullable()->unique();
            $table->string('lot_number', 100)->nullable()->index();
            $table->date('expired_date')->nullable()->index();
            $table->string('location_name')->nullable();
            $table->string('status', 30)->default('available')->index();
            $table->decimal('initial_quantity_kg', 14, 4)->default(0);
            $table->decimal('balance_quantity_kg', 14, 4)->default(0);
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('inventory_lot_id')->nullable()->after('ingredient_id')->index();
        });

        Schema::create('warehouse_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->index();
            $table->string('withdrawal_number', 60)->index();
            $table->date('withdrawal_date')->index();
            $table->string('division_code', 30)->index();
            $table->string('purpose_reference')->nullable();
            $table->string('shift', 30)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignId('taken_by')->index();
            $table->foreignId('verified_by')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->unique(['sppg_unit_id', 'withdrawal_number']);
        });

        Schema::create('warehouse_withdrawal_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_withdrawal_id')->index();
            $table->foreignId('ingredient_id')->index();
            $table->foreignId('inventory_lot_id')->nullable()->index();
            $table->string('ingredient_name_snapshot');
            $table->string('lot_number_snapshot')->nullable();
            $table->date('expiry_date_snapshot')->nullable();
            $table->decimal('taken_quantity_kg', 14, 4);
            $table->decimal('verified_quantity_kg', 14, 4)->nullable();
            $table->string('condition_status', 30)->default('good');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_withdrawal_items');
        Schema::dropIfExists('warehouse_withdrawals');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('inventory_lot_id'));
        Schema::dropIfExists('inventory_lots');
    }
};
