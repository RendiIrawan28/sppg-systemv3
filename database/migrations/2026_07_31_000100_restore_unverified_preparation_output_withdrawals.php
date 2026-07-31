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

        // Versi lama menandai pengambilan sebagai verified tanpa pemeriksa dan waktu
        // verifikasi. Jumlahnya sudah dicadangkan dari stok Hasil Persiapan, sehingga
        // cukup dikembalikan ke status menunggu tanpa mengubah saldo.
        DB::table('preparation_output_withdrawals')
            ->where('status', 'verified')
            ->whereNull('verified_by')
            ->whereNull('verified_at')
            ->update([
                'verified_quantity' => null,
                'status' => 'waiting_verification',
                'review_notes' => 'Menunggu verifikasi aktual oleh Divisi Persiapan.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Tidak dikembalikan otomatis karena verifikasi aktual mungkin sudah dilakukan
        // setelah migration ini berjalan.
    }
};
