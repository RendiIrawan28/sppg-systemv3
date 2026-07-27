<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processing_documentations')) {
            DB::table('processing_documentations')
                ->where('documentation_type', 'after')
                ->update(['documentation_type' => 'finished_output']);
        }

        if (Schema::hasTable('portioning_sessions')) {
            DB::table('portioning_sessions')
                ->where('state', 'handed_over')
                ->update(['state' => 'completed']);
        }
    }

    public function down(): void
    {
        // Normalisasi data ke alur aktif tidak dikembalikan ke konsep lama.
    }
};
