<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Models\FieldDistributionPlan;
use App\Models\ProcessingBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProcessingAutoBatchCleanupService
{
    /** @var array<string, array{table: string, column: string, type?: string}> */
    private const ACTIVITY_SOURCES = [
        'bahan' => ['table' => 'processing_material_usages', 'column' => 'processing_batch_id'],
        'suhu' => ['table' => 'processing_temperature_logs', 'column' => 'processing_batch_id'],
        'dokumentasi' => ['table' => 'processing_documentations', 'column' => 'processing_batch_id'],
        'riwayat' => ['table' => 'processing_histories', 'column' => 'processing_batch_id'],
        'retur' => ['table' => 'processing_returns', 'column' => 'processing_batch_id'],
        'hasil_persiapan' => ['table' => 'preparation_output_withdrawals', 'column' => 'processing_batch_id'],
        'serah_hasil_lama' => ['table' => 'processing_handovers', 'column' => 'processing_batch_id'],
        'langkah_lama' => ['table' => 'processing_steps', 'column' => 'processing_batch_id'],
        'penyimpangan_lama' => ['table' => 'processing_deviations', 'column' => 'processing_batch_id'],
        'tujuan_lama' => ['table' => 'processing_batch_destinations', 'column' => 'processing_batch_id'],
        'serah_persiapan_lama' => ['table' => 'preparation_material_handovers', 'column' => 'processing_batch_id'],
        'bahan_gudang' => ['table' => 'warehouse_withdrawals', 'column' => 'reference_id', 'type' => 'processing_batch'],
        'hasil_ke_pemorsian' => ['table' => 'portioning_supplies', 'column' => 'source_id', 'type' => 'processing_batch'],
    ];

    /**
     * @param  callable(array<string, mixed>): void  $report
     * @return array{checked: int, candidates: int, deleted: int, skipped: int}
     */
    public function scan(callable $report, bool $execute = false, ?int $batchId = null): array
    {
        $this->assertRequiredTablesExist();
        $summary = ['checked' => 0, 'candidates' => 0, 'deleted' => 0, 'skipped' => 0];

        ProcessingBatch::query()
            ->whereNotNull('field_distribution_plan_id')
            ->when($batchId, fn ($query) => $query->whereKey($batchId))
            ->with('fieldDistributionPlan')
            ->orderBy('id')
            ->chunkById(100, function (Collection $batches) use ($report, $execute, &$summary): void {
                $counts = $this->activityCounts($batches->modelKeys());

                foreach ($batches as $batch) {
                    $reasons = $this->reasons($batch, $counts[$batch->getKey()] ?? []);
                    $deleted = false;

                    if ($execute && $reasons === []) {
                        // Periksa ulang di bawah kunci sebelum mengubah referensi atau soft delete.
                        [$reasons, $deleted] = $this->deleteIfStillEmpty($batch->getKey());
                    }

                    $summary['checked']++;
                    $summary[$reasons === [] ? 'candidates' : 'skipped']++;
                    if ($deleted) {
                        $summary['deleted']++;
                    }

                    $report([
                        'id' => $batch->getKey(),
                        'plan_id' => $batch->field_distribution_plan_id,
                        'plan_number' => $batch->fieldDistributionPlan?->plan_number,
                        'menu' => $batch->product_name ?: $batch->menu_name_snapshot,
                        'state' => $batch->state?->value,
                        'status' => $batch->status?->value,
                        'activity' => $counts[$batch->getKey()] ?? [],
                        'reasons' => $reasons,
                        'deleted' => $deleted,
                    ]);
                }
            });

        return $summary;
    }

    /** @return array{0: array<int, string>, 1: bool} */
    private function deleteIfStillEmpty(int $batchId): array
    {
        return DB::transaction(function () use ($batchId): array {
            $batch = ProcessingBatch::query()
                ->with('fieldDistributionPlan')
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if (! $batch) {
                return [['Batch sudah tidak tersedia.'], false];
            }

            $counts = $this->activityCounts([$batchId]);
            $reasons = $this->reasons($batch, $counts[$batchId] ?? []);
            if ($reasons !== []) {
                return [$reasons, false];
            }

            // Kedua tautan ini dibuat otomatis oleh generator lama, bukan bukti
            // pekerjaan nyata. Aktivitas dan serah hasil telah diperiksa di atas.
            DB::table('field_distribution_plans')
                ->where('processing_batch_id', $batchId)
                ->update(['processing_batch_id' => null, 'updated_at' => now()]);
            DB::table('portioning_sessions')
                ->where('processing_batch_id', $batchId)
                ->update(['processing_batch_id' => null, 'updated_at' => now()]);
            $batch->delete();

            Log::info('Batch Pengolahan otomatis kosong di-soft-delete', [
                'batch_id' => $batchId,
                'plan_id' => $batch->field_distribution_plan_id,
                'menu' => $batch->product_name ?: $batch->menu_name_snapshot,
                'deleted_at' => $batch->deleted_at?->toIso8601String(),
                'reason' => 'planned/draft, catatan generator cocok, tanpa aktivitas atau handover',
            ]);

            return [[], true];
        });
    }

    /** @param array<string, int> $activity
     * @return array<int, string>
     */
    private function reasons(ProcessingBatch $batch, array $activity): array
    {
        $reasons = [];
        $plan = $batch->fieldDistributionPlan;

        if ($batch->state !== ProcessingBatchState::Planned || $batch->status !== OperationalReportStatus::Draft) {
            $reasons[] = 'Status bukan planned/draft.';
        }
        if (! $plan || (int) $plan->sppg_unit_id !== (int) $batch->sppg_unit_id) {
            $reasons[] = 'Rencana asal tidak ada atau berbeda unit.';
        } elseif (! in_array(trim((string) $batch->notes), $this->generatedNotes($plan), true)) {
            $reasons[] = 'Catatan tidak cocok dengan penanda generator lama.';
        } elseif ($batch->menu_name_snapshot !== $plan->menu_name_snapshot
            || $batch->product_name !== $plan->menu_name_snapshot
            || abs((float) $batch->target_output_quantity - (float) $plan->planned_total_portions) > 0.0001
            || $batch->target_output_unit !== 'porsi') {
            $reasons[] = 'Nama menu atau target batch telah berubah dari snapshot otomatis.';
        }
        if ($batch->started_at || $batch->completed_at || $batch->petugas_id
            || filled($batch->petugas_name_snapshot)
            || $batch->submitted_by || $batch->submitted_at || $batch->division_approved_by
            || $batch->division_approved_at || $batch->verified_by || $batch->verified_at
            || $batch->portioning_session_id || $batch->portioning_handed_over_at
            || $batch->portioning_handed_over_by || $batch->portioning_received_at
            || $batch->portioning_received_by || filled($batch->review_notes)) {
            $reasons[] = 'Sudah memiliki petugas, waktu kerja, persetujuan, atau serah hasil.';
        }
        if ((float) $batch->actual_output_quantity !== 0.0 || filled($batch->actual_output_unit)
            || filled($batch->source_system)) {
            $reasons[] = 'Sudah memiliki hasil aktual atau sumber data lain.';
        }
        foreach ($activity as $name => $count) {
            if ($count > 0) {
                $reasons[] = "Memiliki {$name}: {$count}.";
            }
        }

        return $reasons;
    }

    /** @return array<int, string> */
    private function generatedNotes(FieldDistributionPlan $plan): array
    {
        $serviceDate = $plan->service_date ?: $plan->distribution_date;

        return array_filter([
            "Dibuat dari rencana distribusi {$plan->plan_number}.",
            $serviceDate
                ? sprintf('Batch rapel terpisah untuk pelayanan %s dari rencana distribusi %s.',
                    $serviceDate->format('d-m-Y'), $plan->plan_number)
                : null,
        ]);
    }

    /**
     * @param  array<int, int>  $batchIds
     * @return array<int, array<string, int>>
     */
    private function activityCounts(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        $result = [];
        foreach (self::ACTIVITY_SOURCES as $name => $source) {
            if (! Schema::hasTable($source['table'])
                || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }
            $column = $source['column'];
            $query = DB::table($source['table'])->whereIn($column, $batchIds);
            if (isset($source['type'])) {
                $query->where($source['table'] === 'warehouse_withdrawals' ? 'reference_type' : 'source_type', $source['type']);
            }
            foreach ($query->selectRaw("{$column}, COUNT(*) as activity_count")
                ->groupBy($column)->pluck('activity_count', $column) as $id => $count) {
                $result[(int) $id][$name] = (int) $count;
            }
        }

        return $result;
    }

    private function assertRequiredTablesExist(): void
    {
        foreach (['processing_batches', 'field_distribution_plans', 'portioning_sessions',
            'processing_material_usages', 'processing_temperature_logs',
            'processing_documentations', 'processing_histories', 'processing_returns'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cleanup dibatalkan: tabel {$table} belum tersedia.");
            }
        }
    }
}
