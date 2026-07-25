<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_lots')
            ->whereNotNull('stock_receipt_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($lots): void {
                foreach ($lots as $lot) {
                    $receiptItem = DB::table('stock_receipt_items')->find($lot->stock_receipt_item_id);
                    if (! $receiptItem) {
                        continue;
                    }

                    $unit = $receiptItem->unit_snapshot ?: 'kg';
                    $initial = (float) ($receiptItem->accepted_quantity ?? 0);
                    if ($initial <= 0) {
                        continue;
                    }

                    $initialKg = (float) $lot->initial_quantity_kg;
                    $balanceKg = (float) $lot->balance_quantity_kg;
                    $remainingRatio = $initialKg > 0 ? max(0, min(1, $balanceKg / $initialKg)) : 1;
                    $balance = round($initial * $remainingRatio, 4);
                    $factor = $initialKg > 0 ? $initial / $initialKg : 1;

                    DB::table('inventory_lots')->where('id', $lot->id)->update([
                        'unit_snapshot' => $unit,
                        'initial_quantity' => $initial,
                        'balance_quantity' => $balance,
                    ]);

                    DB::table('stock_movements')->where('inventory_lot_id', $lot->id)
                        ->orderBy('id')->chunkById(200, function ($movements) use ($unit, $factor): void {
                            foreach ($movements as $movement) {
                                DB::table('stock_movements')->where('id', $movement->id)->update([
                                    'unit_snapshot' => $unit,
                                    'quantity_in' => round((float) $movement->quantity_in_kg * $factor, 4),
                                    'quantity_out' => round((float) $movement->quantity_out_kg * $factor, 4),
                                ]);
                            }
                        });
                }
            });
    }

    public function down(): void
    {
        // Data satuan asli tidak dikembalikan menjadi kilogram karena akan menghilangkan informasi.
    }
};
