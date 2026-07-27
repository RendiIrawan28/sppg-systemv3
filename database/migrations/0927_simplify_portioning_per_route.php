<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portioning_sessions', function (Blueprint $table): void {
            $table->string('leftover_mode', 20)->nullable()->after('notes');
        });

        Schema::table('portioning_leftover_records', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 3)->nullable()->after('weight_kg');
            $table->string('unit_name', 50)->nullable()->after('quantity');
        });

        DB::table('portioning_leftover_records')
            ->whereNull('quantity')
            ->update([
                'quantity' => DB::raw('weight_kg'),
                'unit_name' => 'kg',
            ]);
    }

    public function down(): void
    {
        Schema::table('portioning_leftover_records', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'unit_name']);
        });

        Schema::table('portioning_sessions', function (Blueprint $table): void {
            $table->dropColumn('leftover_mode');
        });
    }
};
