<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('preparation_output_withdrawals')) {
            return;
        }

        DB::table('preparation_output_withdrawals')
            ->where('status', 'waiting_verification')
            ->update([
                'verified_quantity' => DB::raw('requested_quantity'),
                'status' => 'verified',
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Status lama tidak dikembalikan karena data yang sudah diambil tidak dapat
        // dibedakan secara aman dari pengambilan yang pernah diverifikasi sebelumnya.
    }
};
