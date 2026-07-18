<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('inventory_lot_id')->index();
            $table->string('adjustment_number', 60)->index();
            $table->date('adjustment_date')->index();
            $table->string('type', 30)->index();
            $table->decimal('system_quantity_kg', 14, 4);
            $table->decimal('actual_quantity_kg', 14, 4);
            $table->decimal('difference_quantity_kg', 14, 4);
            $table->string('status', 30)->default('draft')->index();
            $table->text('reason');
            $table->foreignId('created_by')->index();
            $table->foreignId('verified_by')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // Saldo historis sebelum sistem lot menjadi satu lot awal per bahan.
        $legacy = DB::table('stock_movements')
            ->whereNull('inventory_lot_id')
            ->select('sppg_unit_id', 'ingredient_id', DB::raw('SUM(quantity_in_kg - quantity_out_kg) balance_kg'))
            ->groupBy('sppg_unit_id', 'ingredient_id')->get();

        foreach ($legacy as $row) {
            if ((float) $row->balance_kg <= 0) continue;
            $lotId = DB::table('inventory_lots')->insertGetId([
                'sppg_unit_id' => $row->sppg_unit_id, 'ingredient_id' => $row->ingredient_id,
                'lot_number' => 'SALDO-AWAL-'.str_pad((string) $row->ingredient_id, 5, '0', STR_PAD_LEFT),
                'location_name' => 'Gudang Utama', 'status' => 'available',
                'initial_quantity_kg' => $row->balance_kg, 'balance_quantity_kg' => $row->balance_kg,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stock_movements')->whereNull('inventory_lot_id')
                ->where('sppg_unit_id', $row->sppg_unit_id)->where('ingredient_id', $row->ingredient_id)
                ->update(['inventory_lot_id' => $lotId]);
        }
    }

    public function down(): void { Schema::dropIfExists('stock_adjustments'); }
};
