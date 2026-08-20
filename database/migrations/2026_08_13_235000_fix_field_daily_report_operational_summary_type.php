<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_daily_reports')
            || ! Schema::hasColumn('field_daily_reports', 'operational_summary')) {
            return;
        }

        Schema::table('field_daily_reports', function (Blueprint $table): void {
            $table->text('operational_summary')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan menjadi decimal karena ringkasan operasional selalu berupa teks.
    }
};
