<?php

namespace App\Console\Commands;

use App\Services\ProcessingAutoBatchCleanupService;
use Illuminate\Console\Command;
use Throwable;

class CleanupEmptyAutoProcessingBatches extends Command
{
    protected $signature = 'processing:cleanup-empty-auto-generated
        {--dry-run : Tampilkan calon penghapusan tanpa mengubah data (mode bawaan)}
        {--execute : Soft delete hanya batch yang terbukti kosong dan dibuat otomatis}
        {--id= : Batasi pemeriksaan pada satu ID batch}';

    protected $description = 'Pratinjau atau bersihkan batch Pengolahan otomatis lama yang benar-benar kosong.';

    public function handle(ProcessingAutoBatchCleanupService $cleanup): int
    {
        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Pilih salah satu: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $id = $this->option('id');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id <= 0)) {
            $this->error('Opsi --id harus berupa ID batch positif.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $this->warn($execute
            ? 'MODE EKSEKUSI: hanya batch otomatis kosong yang akan di-soft-delete.'
            : 'MODE PRATINJAU: tidak ada data yang diubah.');

        try {
            $summary = $cleanup->scan(function (array $row): void {
                $label = sprintf(
                    'Batch #%d | Rencana %s (#%s) | %s | %s/%s',
                    $row['id'], $row['plan_number'] ?: 'tidak ada',
                    $row['plan_id'] ?: '-', $row['menu'] ?: '-',
                    $row['state'] ?: '-', $row['status'] ?: '-',
                );
                $this->line($label);
                $this->line('  Aktivitas: '.($row['activity'] === []
                    ? 'tidak ada'
                    : collect($row['activity'])->map(fn ($count, $name) => "{$name}={$count}")->implode(', ')));
                if ($row['reasons'] !== []) {
                    $this->warn('  LEWATI: '.implode(' ', $row['reasons']));
                } else {
                    $this->info($row['deleted'] ? '  SOFT DELETE SELESAI' : '  AMAN UNTUK DIHAPUS (pratinjau)');
                }
            }, $execute, $id === null ? null : (int) $id);
        } catch (Throwable $exception) {
            $this->error('Cleanup dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf(
            'Diperiksa: %d | Calon aman: %d | Dilewati: %d | Soft delete: %d',
            $summary['checked'], $summary['candidates'], $summary['skipped'], $summary['deleted'],
        ));

        return self::SUCCESS;
    }
}
