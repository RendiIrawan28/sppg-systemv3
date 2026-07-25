<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        Schema::table('procurement_request_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('procurement_request_items', 'requested_quantity')) {
                $table->decimal('requested_quantity', 14, 4)
                    ->nullable()
                    ->after('unit_snapshot');
            }

            if (! Schema::hasColumn('procurement_request_items', 'approved_quantity')) {
                $table->decimal('approved_quantity', 14, 4)
                    ->nullable()
                    ->after('requested_quantity');
            }
        });

        DB::table('procurement_request_items')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    DB::table('procurement_request_items')
                        ->where('id', $item->id)
                        ->update([
                            'requested_quantity' => $item->requested_quantity
                                ?? $item->requested_quantity_kg,
                            'approved_quantity' => $item->approved_quantity
                                ?? $item->approved_quantity_kg
                                ?? $item->requested_quantity
                                ?? $item->requested_quantity_kg,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        Schema::table('procurement_request_items', function (Blueprint $table): void {
            if (Schema::hasColumn('procurement_request_items', 'approved_quantity')) {
                $table->dropColumn('approved_quantity');
            }

            if (Schema::hasColumn('procurement_request_items', 'requested_quantity')) {
                $table->dropColumn('requested_quantity');
            }
        });
    }
};
