<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portioning_route_records', function (Blueprint $table): void {
            $table->string('photo_original_name')->nullable()->after('photo_path');
        });

        Schema::table('portioning_leftover_records', function (Blueprint $table): void {
            $table->string('photo_original_name')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('portioning_leftover_records', function (Blueprint $table): void {
            $table->dropColumn('photo_original_name');
        });

        Schema::table('portioning_route_records', function (Blueprint $table): void {
            $table->dropColumn('photo_original_name');
        });
    }
};
