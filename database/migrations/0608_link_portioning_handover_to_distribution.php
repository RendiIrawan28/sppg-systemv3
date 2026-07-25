<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('portioning_handover_id')->nullable()->after('portioning_session_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('distribution_runs', function (Blueprint $table): void {
            $table->dropColumn('portioning_handover_id');
        });
    }
};
