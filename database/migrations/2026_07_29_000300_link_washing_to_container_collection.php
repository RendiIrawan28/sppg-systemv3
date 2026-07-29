<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('washing_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('washing_sessions', 'container_collection_run_id')) {
                $table->foreignId('container_collection_run_id')->nullable()->unique()->after('distribution_run_id');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('washing_sessions', 'container_collection_run_id')) {
            Schema::table('washing_sessions', function (Blueprint $table): void {
                $table->dropUnique(['container_collection_run_id']);
                $table->dropColumn('container_collection_run_id');
            });
        }
    }
};
