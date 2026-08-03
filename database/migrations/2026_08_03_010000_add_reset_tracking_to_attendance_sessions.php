<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table): void {
            $table->foreignId('deleted_by')->nullable()->after('corrected_at')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['deleted_by', 'deletion_reason', 'deleted_at']);
        });
    }
};
