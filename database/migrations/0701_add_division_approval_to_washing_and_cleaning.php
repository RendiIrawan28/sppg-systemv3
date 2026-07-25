<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['washing_sessions', 'cleaning_sessions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('division_approved_by')
                    ->nullable()
                    ->after('submitted_at')
                    ->constrained('users')
                    ->nullOnDelete();
                $blueprint->timestamp('division_approved_at')
                    ->nullable()
                    ->after('division_approved_by');
            });
        }
    }

    public function down(): void
    {
        foreach (['washing_sessions', 'cleaning_sessions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('division_approved_by');
                $blueprint->dropColumn('division_approved_at');
            });
        }
    }
};
