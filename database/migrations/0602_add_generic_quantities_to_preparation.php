<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_session_items', function (Blueprint $table): void {
            $table->string('unit_snapshot', 80)->default('kg')->after('ingredient_name_snapshot');
            $table->decimal('received_quantity', 14, 4)->nullable()->after('unit_snapshot');
            $table->decimal('processed_quantity', 14, 4)->nullable()->after('received_quantity');
            $table->decimal('waste_quantity', 14, 4)->nullable()->after('processed_quantity');
        });
        Schema::table('preparation_handovers', function (Blueprint $table): void {
            $table->json('quantity_summary')->nullable()->after('total_clean_weight_kg');
        });

        DB::table('preparation_session_items')->update([
            'unit_snapshot' => 'kg',
            'received_quantity' => DB::raw('received_weight_kg'),
            'processed_quantity' => DB::raw('clean_weight_kg'),
            'waste_quantity' => DB::raw('waste_weight_kg'),
        ]);
    }

    public function down(): void
    {
        Schema::table('preparation_handovers', fn (Blueprint $table) => $table->dropColumn('quantity_summary'));
        Schema::table('preparation_session_items', fn (Blueprint $table) => $table->dropColumn([
            'unit_snapshot', 'received_quantity', 'processed_quantity', 'waste_quantity',
        ]));
    }
};
