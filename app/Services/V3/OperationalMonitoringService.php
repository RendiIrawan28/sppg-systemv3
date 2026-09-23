<?php

namespace App\Services\V3;

use App\Models\AttendanceSession;
use App\Models\CleaningFinding;
use App\Models\CleaningSession;
use App\Models\DistributionIncident;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\FieldIncident;
use App\Models\InventoryLot;
use App\Models\PortioningReturn;
use App\Models\PortioningSession;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\ProcessingReturn;
use App\Models\SppgUnit;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\WarehouseWithdrawal;
use App\Models\WarehouseWithdrawalItem;
use App\Models\WashingDeviation;
use App\Models\WashingSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

final class OperationalMonitoringService
{
    /** @return array<string, mixed> */
    public function for(SppgUnit $unit, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $unitId = (int) $unit->getKey();

        $progress = $this->progress($unitId, $date);
        $overview = $this->overview($unitId, $date, $progress);
        $warehouse = $this->warehouse($unitId, $date);
        $preparation = $this->preparation($unitId, $date);
        $processing = $this->processing($unitId, $date);

        return compact('overview', 'progress', 'warehouse', 'preparation', 'processing');
    }

    /** @return array<string, mixed> */
    private function preparation(int $unitId, string $date): array
    {
        $sessions = PreparationSession::query()
            ->with(['items.resultDocumentation', 'petugas'])
            ->where('sppg_unit_id', $unitId)
            ->whereDate('preparation_date', $date)
            ->latest('started_at')
            ->latest('id')
            ->get();

        $items = $sessions->flatMap(fn (PreparationSession $session) => $session->items);
        $receivedKg = (float) $items->sum(fn ($item): float => (float) ($item->received_weight_kg ?? 0));
        $cleanKg = (float) $items->sum(fn ($item): float => (float) ($item->clean_weight_kg ?? 0));
        $wasteKg = (float) $items->sum(fn ($item): float => (float) ($item->waste_weight_kg ?? 0));
        $completed = $sessions->where('state', 'completed')->count();

        $rows = $sessions
            ->flatMap(function (PreparationSession $session): array {
                [$status, $tone] = $this->preparationStatus($session);

                return $session->items->map(function ($item) use ($session, $status, $tone): array {
                    $photoPath = $item->resultDocumentation?->photo_path;

                    return [
                        'ingredient' => $item->ingredient_name_snapshot ?: '-',
                        'received' => $this->quantityLabel($item->received_quantity, $item->unit_snapshot),
                        'received_kg' => (float) ($item->received_weight_kg ?? 0),
                        'result' => $this->quantityLabel($item->processed_quantity, $item->processed_unit_snapshot),
                        'result_kg' => (float) ($item->clean_weight_kg ?? 0),
                        'waste' => $this->quantityLabel($item->waste_quantity, $item->waste_unit_snapshot),
                        'waste_kg' => (float) ($item->waste_weight_kg ?? 0),
                        'officer' => $session->petugas?->name ?: '-',
                        'time' => ($session->completed_at ?? $session->started_at ?? $session->created_at)?->format('H:i') ?? '-',
                        'status' => $status,
                        'status_tone' => $tone,
                        'photo_url' => $photoPath ? Storage::disk('public')->url($photoPath) : null,
                    ];
                })->all();
            })
            ->take(200)
            ->values()
            ->all();

        return [
            'cards' => [
                $this->miniCard('Bahan Diproses', $items->count(), 'Jumlah item bahan pada sesi Persiapan', 'clipboard', 'sky'),
                $this->miniCard('Total Diterima', $this->kilogramLabel($receivedKg), $receivedKg > 0 ? 'Bobot kanonik dari '.$items->count().' item' : 'Bobot kanonik belum tersedia', 'box', 'violet'),
                $this->miniCard('Total Hasil', $this->kilogramLabel($cleanKg), 'Hasil bersih Persiapan', 'check-badge', 'emerald'),
                $this->miniCard('Total Limbah', $this->kilogramLabel($wasteKg), 'Limbah/sisa yang tercatat', 'recycle', $wasteKg > 0 ? 'amber' : 'slate'),
                $this->miniCard('Status Sesi', $completed.'/'.$sessions->count(), 'Sesi selesai pada tanggal terpilih', 'settings', $sessions->isNotEmpty() && $completed === $sessions->count() ? 'emerald' : 'amber'),
            ],
            'rows' => $rows,
            'detail_url' => route('v3.preparation.index', ['tanggal' => $date]),
        ];
    }

    /** @return array<string, mixed> */
    private function processing(int $unitId, string $date): array
    {
        $batches = ProcessingBatch::query()
            ->with(['materialUsages', 'temperatureLogs', 'documentations', 'petugas'])
            ->where('sppg_unit_id', $unitId)
            ->whereDate('production_date', $date)
            ->latest('started_at')
            ->latest('id')
            ->get();

        $running = $batches->filter(fn (ProcessingBatch $batch): bool => $batch->state?->value === 'in_progress')->count();
        $completed = $batches->filter(fn (ProcessingBatch $batch): bool => $batch->state?->value === 'completed')->count();
        $handedOver = $batches->whereNotNull('portioning_handed_over_at')->count();
        [$outputValue, $outputDetail] = $this->summarizeQuantities(
            $batches->map(fn (ProcessingBatch $batch): array => [
                'quantity' => (float) ($batch->actual_output_quantity ?? 0),
                'unit' => $batch->actual_output_unit ?: '-',
            ])->all(),
        );

        $rows = $batches->map(function (ProcessingBatch $batch): array {
            [$status, $tone] = $this->processingStatus($batch);
            [$handoverStatus, $handoverTone] = $this->handoverStatus($batch);
            $temperature = $batch->temperatureLogs->sortByDesc('checked_at')->first();
            $photos = $batch->documentations
                ->filter(fn ($documentation): bool => filled($documentation->photo_path))
                ->map(fn ($documentation): array => [
                    'url' => Storage::disk('public')->url($documentation->photo_path),
                    'title' => $documentation->documentation_type === 'finished_output'
                        ? 'Foto hasil akhir'
                        : ($documentation->caption ?: 'Dokumentasi produksi'),
                ])
                ->concat($batch->temperatureLogs
                    ->filter(fn ($temperature): bool => filled($temperature->photo_path))
                    ->map(fn ($temperature): array => [
                        'url' => Storage::disk('public')->url($temperature->photo_path),
                        'title' => 'Foto suhu · '.($temperature->product_name ?: 'Pangan matang'),
                    ]))
                ->unique('url')
                ->values();

            return [
                'product' => $batch->product_name ?: $batch->menu_name_snapshot ?: '-',
                'batch' => $batch->batch_number ?: '-',
                'materials' => $batch->materialUsages->map(fn ($usage): array => [
                    'name' => $usage->material_name ?: '-',
                    'quantity' => $this->quantityLabel($usage->quantity, $usage->unit_name),
                ])->values()->all(),
                'started_at' => $batch->started_at?->format('H:i') ?? '-',
                'completed_at' => $batch->completed_at?->format('H:i') ?? '-',
                'temperature' => $temperature?->temperature_celsius !== null
                    ? number_format((float) $temperature->temperature_celsius, 1, ',', '.').' °C'
                    : '-',
                'output' => $this->quantityLabel($batch->actual_output_quantity, $batch->actual_output_unit),
                'officer' => $batch->petugas?->name ?: $batch->petugas_name_snapshot ?: '-',
                'status' => $status,
                'status_tone' => $tone,
                'handover_status' => $handoverStatus,
                'handover_tone' => $handoverTone,
                'photos' => $photos->all(),
            ];
        })->take(200)->values()->all();

        return [
            'cards' => [
                $this->miniCard('Total Batch', $batches->count(), 'Batch produksi pada tanggal terpilih', 'nutrition', 'sky'),
                $this->miniCard('Sedang Berjalan', $running, 'Batch yang sedang diproses', 'settings', $running > 0 ? 'amber' : 'slate'),
                $this->miniCard('Produksi Selesai', $completed, 'Batch dengan produksi selesai', 'check-badge', 'emerald'),
                $this->miniCard('Hasil Produksi', $outputValue, $outputDetail, 'calculator', 'violet'),
                $this->miniCard('Diserahkan', $handedOver.'/'.$batches->count(), 'Hasil diserahkan ke Pemorsian', 'arrow-up-right', $batches->isNotEmpty() && $handedOver === $batches->count() ? 'emerald' : 'amber'),
            ],
            'rows' => $rows,
            'detail_url' => route('v3.processing.index', ['tanggal' => $date]),
        ];
    }

    /** @param array<int, array<string, mixed>> $progress
     * @return array<string, mixed>
     */
    private function overview(int $unitId, string $date, array $progress): array
    {
        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->where('status', '!=', 'cancelled')
            ->where(function (Builder $query) use ($date): void {
                $query->whereDate('service_date', $date)
                    ->orWhereDate('distribution_date', $date);
            })
            ->get();

        $planIds = $plans->pluck('id');
        $destinationCount = $planIds->isEmpty()
            ? 0
            : FieldDistributionPlanDestination::query()
                ->whereIn('field_distribution_plan_id', $planIds)
                ->where('total_portions', '>', 0)
                ->get(['destination_type', 'destination_id', 'destination_code_snapshot', 'destination_name_snapshot'])
                ->unique(function (FieldDistributionPlanDestination $destination): string {
                    return implode(':', [
                        $destination->destination_type ?: 'unknown',
                        $destination->destination_id ?: $destination->destination_code_snapshot ?: $destination->destination_name_snapshot,
                    ]);
                })
                ->count();

        $withdrawalItems = WarehouseWithdrawalItem::query()
            ->whereHas('withdrawal', fn (Builder $query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereDate('withdrawal_date', $date))
            ->get(['id', 'taken_quantity_kg', 'verified_quantity_kg']);

        $batchQuery = ProcessingBatch::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('production_date', $date);
        $batchCount = (clone $batchQuery)->count();
        $completedBatches = (clone $batchQuery)->where('state', 'completed')->count();

        $runQuery = DistributionRun::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date);
        $runCount = (clone $runQuery)->count();
        $completedRuns = (clone $runQuery)
            ->whereIn('state', ['destinations_completed', 'returned'])
            ->count();

        $incidentCount = FieldIncident::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('incident_date', $date)
            ->count();

        $incidentCount += DistributionIncident::query()
            ->whereDate('occurred_at', $date)
            ->whereHas('distributionRun', fn (Builder $query) => $query->where('sppg_unit_id', $unitId))
            ->count();

        $incidentCount += WashingDeviation::query()
            ->whereDate('occurred_at', $date)
            ->whereHas('washingSession', fn (Builder $query) => $query->where('sppg_unit_id', $unitId))
            ->count();

        $incidentCount += CleaningFinding::query()
            ->whereDate('found_at', $date)
            ->whereHas('cleaningSession', fn (Builder $query) => $query->where('sppg_unit_id', $unitId))
            ->count();

        $finishedStages = collect($progress)->whereIn('state', ['completed', 'verified'])->count();
        $progressPercent = $progress === []
            ? 0
            : (int) round(($finishedStages / count($progress)) * 100);

        $outKg = (float) $withdrawalItems->sum(function (WarehouseWithdrawalItem $item): float {
            $verified = (float) ($item->verified_quantity_kg ?? 0);

            return $verified > 0 ? $verified : (float) ($item->taken_quantity_kg ?? 0);
        });

        return [
            'cards' => [
                $this->card(
                    'Penerima Hari Ini',
                    (int) $plans->sum('confirmed_beneficiaries'),
                    'Penerima terkonfirmasi pada rencana distribusi',
                    'users',
                    'sky',
                    route('v3.field.plans.index'),
                    'field_planning.view',
                ),
                $this->card(
                    'Porsi Hari Ini',
                    (int) $plans->sum('planned_total_portions'),
                    'Total porsi kecil dan besar yang direncanakan',
                    'calculator',
                    'emerald',
                    route('v3.field.plans.index'),
                    'field_planning.view',
                ),
                $this->card(
                    'Tujuan Distribusi',
                    $destinationCount,
                    'Sekolah dan posyandu dengan porsi di atas nol',
                    'route',
                    'violet',
                    route('v3.field.plans.index'),
                    'field_planning.view',
                ),
                $this->card(
                    'Progres Operasional',
                    $progressPercent.'%',
                    $finishedStages.' dari '.count($progress).' tahap selesai / diverifikasi',
                    'check-badge',
                    'sky',
                ),
                $this->card(
                    'Bahan Keluar Gudang',
                    $withdrawalItems->count(),
                    $outKg > 0
                        ? number_format($outKg, 2, ',', '.').' kg tercatat pada '.$withdrawalItems->count().' baris bahan'
                        : $withdrawalItems->count().' baris bahan diambil dari gudang',
                    'box',
                    'amber',
                    route('v3.warehouse.withdrawals.index'),
                    'stock.view',
                ),
                $this->card(
                    'Produksi Selesai',
                    $completedBatches.'/'.$batchCount,
                    'Batch Pengolahan yang sudah selesai',
                    'nutrition',
                    $batchCount > 0 && $completedBatches === $batchCount ? 'emerald' : 'amber',
                    route('v3.processing.index'),
                    'processing.view',
                ),
                $this->card(
                    'Distribusi Selesai',
                    $completedRuns.'/'.$runCount,
                    'Rute telah menyelesaikan seluruh tujuan / kembali ke SPPG',
                    'truck',
                    $runCount > 0 && $completedRuns === $runCount ? 'emerald' : 'amber',
                    route('v3.operations.index', ['module' => 'distribusi']),
                    'distribution.view',
                ),
                $this->card(
                    'Insiden / Masalah',
                    $incidentCount,
                    'Insiden lapangan, distribusi, pencucian, dan temuan kebersihan',
                    'alert',
                    $incidentCount > 0 ? 'rose' : 'emerald',
                    route('v3.field.incidents.index'),
                    'field_incidents.view',
                ),
            ],
            'date' => $date,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function progress(int $unitId, string $date): array
    {
        $receipts = StockReceipt::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('receipt_date', $date);
        $withdrawals = WarehouseWithdrawal::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('withdrawal_date', $date);
        $warehouseTotal = (clone $receipts)->count() + (clone $withdrawals)->count();
        $warehouseVerified = (clone $receipts)->where('status', StockReceipt::STATUS_RECEIVED)->count()
            + (clone $withdrawals)->where('status', WarehouseWithdrawal::VERIFIED)->count();

        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->where('status', '!=', 'cancelled')
            ->where(function (Builder $query) use ($date): void {
                $query->whereDate('service_date', $date)->orWhereDate('distribution_date', $date);
            });

        $preparations = PreparationSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('preparation_date', $date);
        $processings = ProcessingBatch::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('production_date', $date);
        $portionings = PortioningSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('portioning_date', $date);
        $distributions = DistributionRun::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date);
        $washings = WashingSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('washing_date', $date);
        $cleanings = CleaningSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('scheduled_date', $date);
        $attendance = AttendanceSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('work_date', $date);

        return [
            $this->stage('Gudang', 'box', $warehouseTotal, $warehouseVerified, $warehouseVerified, route('v3.warehouse.withdrawals.index'), 'stock.view'),
            $this->stage(
                'Asisten Lapangan',
                'route',
                (clone $plans)->count(),
                (clone $plans)->where('status', 'completed')->count(),
                0,
                route('v3.field.plans.index'),
                'field_planning.view',
            ),
            $this->stageFromOperationalQuery('Persiapan', 'clipboard', $preparations, ['completed'], route('v3.preparation.index'), 'preparation.view'),
            $this->stageFromOperationalQuery('Pengolahan', 'nutrition', $processings, ['completed'], route('v3.processing.index'), 'processing.view'),
            $this->stageFromOperationalQuery('Pemorsian', 'calculator', $portionings, ['completed'], route('v3.portioning.index'), 'portioning.view'),
            $this->stageFromOperationalQuery('Distribusi', 'truck', $distributions, ['destinations_completed', 'returned'], route('v3.operations.index', ['module' => 'distribusi']), 'distribution.view'),
            $this->stageFromOperationalQuery('Pencucian', 'droplets', $washings, ['completed', 'ready'], route('v3.operations.index', ['module' => 'pencucian']), 'washing.view'),
            $this->stageFromOperationalQuery('Kebersihan', 'sparkles', $cleanings, ['completed', 'ready'], route('v3.operations.index', ['module' => 'kebersihan']), 'cleaning.view'),
            $this->stage(
                'Presensi',
                'users',
                (clone $attendance)->count(),
                (clone $attendance)->whereNotNull('check_out_at')->count(),
                0,
                route('v3.attendance.index'),
                'attendance.view',
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function warehouse(int $unitId, string $date): array
    {
        $receipts = StockReceipt::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('receipt_date', $date);
        $withdrawals = WarehouseWithdrawal::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('withdrawal_date', $date);
        $preparationReturns = PreparationReturn::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date);
        $processingReturns = ProcessingReturn::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date);
        $portioningReturns = PortioningReturn::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date);

        $stockAttention = InventoryLot::query()
            ->where('sppg_unit_id', $unitId)
            ->where('balance_quantity', '>', 0)
            ->where(function (Builder $query): void {
                $query->whereIn('status', [InventoryLot::QUARANTINE, InventoryLot::REJECTED])
                    ->orWhereDate('expired_date', '<=', now()->addDays(7)->toDateString());
            })
            ->count();

        return [
            'cards' => [
                $this->miniCard('Penerimaan', (clone $receipts)->count(), 'Dokumen barang masuk', 'box', 'sky'),
                $this->miniCard('Pengambilan', (clone $withdrawals)->count(), 'Dokumen barang keluar', 'arrow-up-right', 'amber'),
                $this->miniCard(
                    'Retur',
                    (clone $preparationReturns)->count() + (clone $processingReturns)->count() + (clone $portioningReturns)->count(),
                    'Retur Persiapan + Pengolahan + Pemorsian',
                    'recycle',
                    'violet',
                ),
                $this->miniCard('Stok Kritis', $stockAttention, 'Lot karantina / ditolak / kedaluwarsa ≤7 hari (kondisi saat ini)', 'alert', $stockAttention > 0 ? 'rose' : 'emerald'),
            ],
            'rows' => $this->warehouseRows($unitId, $date),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function warehouseRows(int $unitId, string $date): array
    {
        $rows = collect();

        StockReceiptItem::query()
            ->with(['receipt', 'photos'])
            ->whereHas('receipt', fn (Builder $query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereDate('receipt_date', $date))
            ->get()
            ->each(function (StockReceiptItem $item) use ($rows): void {
                $receipt = $item->receipt;
                $quantity = (float) $item->accepted_quantity > 0
                    ? (float) $item->accepted_quantity
                    : (float) $item->received_quantity;
                $photoPath = $item->photos->first()?->photo_path;

                $rows->push([
                    'timestamp' => $receipt?->received_at ?? $receipt?->created_at,
                    'type' => 'Penerimaan',
                    'type_tone' => 'sky',
                    'reference' => $receipt?->receipt_number ?: '-',
                    'ingredient' => $item->ingredient_name_snapshot ?: '-',
                    'received' => $quantity,
                    'outgoing' => 0.0,
                    'division' => 'Gudang',
                    'unit' => $item->unit_snapshot ?: '-',
                    'officer' => $receipt?->received_by_name ?: '-',
                    'status' => $receipt?->status === StockReceipt::STATUS_RECEIVED ? 'Diterima' : 'Draft',
                    'status_tone' => $receipt?->status === StockReceipt::STATUS_RECEIVED ? 'emerald' : 'slate',
                    'photo_url' => $photoPath ? Storage::disk('public')->url($photoPath) : null,
                    'detail_url' => $receipt ? route('v3.warehouse.receipts.show', $receipt) : route('v3.warehouse.receipts.index'),
                ]);
            });

        WarehouseWithdrawalItem::query()
            ->with(['withdrawal.taker'])
            ->whereHas('withdrawal', fn (Builder $query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereDate('withdrawal_date', $date))
            ->get()
            ->each(function (WarehouseWithdrawalItem $item) use ($rows): void {
                $withdrawal = $item->withdrawal;
                $quantity = (float) $item->actual_quantity > 0
                    ? (float) $item->actual_quantity
                    : (float) $item->requested_quantity;

                [$status, $tone] = match ($withdrawal?->status) {
                    WarehouseWithdrawal::VERIFIED => ['Terverifikasi', 'emerald'],
                    WarehouseWithdrawal::WAITING => ['Menunggu Verifikasi', 'amber'],
                    WarehouseWithdrawal::REVISION => ['Perlu Revisi', 'rose'],
                    WarehouseWithdrawal::REJECTED => ['Ditolak', 'rose'],
                    default => ['Draft', 'slate'],
                };

                $rows->push([
                    'timestamp' => $withdrawal?->verified_at ?? $withdrawal?->submitted_at ?? $withdrawal?->created_at,
                    'type' => 'Pengambilan',
                    'type_tone' => 'amber',
                    'reference' => $withdrawal?->withdrawal_number ?: '-',
                    'ingredient' => $item->ingredient_name_snapshot ?: '-',
                    'received' => 0.0,
                    'outgoing' => $quantity,
                    'division' => $this->divisionLabel($withdrawal?->division_code),
                    'unit' => $item->unit_snapshot ?: '-',
                    'officer' => $withdrawal?->taker?->name ?: '-',
                    'status' => $status,
                    'status_tone' => $tone,
                    'photo_url' => $item->photo_path ? Storage::disk('public')->url($item->photo_path) : null,
                    'detail_url' => route('v3.warehouse.withdrawals.index'),
                ]);
            });

        PreparationReturn::query()
            ->with('returner')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date)
            ->get()
            ->each(function (PreparationReturn $return) use ($rows): void {
                $rows->push($this->returnRow($return, 'Persiapan'));
            });

        ProcessingReturn::query()
            ->with('returner')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date)
            ->get()
            ->each(function (ProcessingReturn $return) use ($rows): void {
                $rows->push($this->returnRow($return, 'Pengolahan'));
            });

        PortioningReturn::query()
            ->with('returner')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('return_date', $date)
            ->get()
            ->each(function (PortioningReturn $return) use ($rows): void {
                $rows->push($this->returnRow($return, 'Pemorsian'));
            });

        return $rows
            ->sortByDesc(fn (array $row) => $row['timestamp']?->getTimestamp() ?? 0)
            ->take(200)
            ->values()
            ->map(function (array $row): array {
                $row['time'] = $row['timestamp']?->format('H:i') ?? '-';
                unset($row['timestamp']);

                return $row;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function returnRow(PreparationReturn|ProcessingReturn|PortioningReturn $return, string $source): array
    {
        $quantity = (float) $return->actual_quantity > 0
            ? (float) $return->actual_quantity
            : (float) $return->requested_quantity;

        [$status, $tone] = match ($return->status) {
            PreparationReturn::VERIFIED => ['Terverifikasi', 'emerald'],
            PreparationReturn::REJECTED => ['Ditolak', 'rose'],
            default => ['Menunggu Verifikasi', 'amber'],
        };

        return [
            'timestamp' => $return->verified_at ?? $return->submitted_at ?? $return->created_at,
            'type' => 'Retur',
            'type_tone' => 'violet',
            'reference' => $return->return_number ?: '-',
            'ingredient' => $return->ingredient_name_snapshot ?: '-',
            'received' => $quantity,
            'outgoing' => 0.0,
            'division' => $source.' → Gudang',
            'unit' => $return->unit_snapshot ?: '-',
            'officer' => $return->returner?->name ?: '-',
            'status' => $status,
            'status_tone' => $tone,
            'photo_url' => $return->photo_path ? Storage::disk('public')->url($return->photo_path) : null,
            'detail_url' => route('v3.warehouse.controls.index'),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function preparationStatus(PreparationSession $session): array
    {
        if ($session->status?->value === 'verified') {
            return ['Diverifikasi', 'emerald'];
        }

        return match ($session->state) {
            'completed' => ['Selesai', 'sky'],
            'in_progress' => ['Berjalan', 'amber'],
            default => ['Belum Mulai', 'slate'],
        };
    }

    /** @return array{0: string, 1: string} */
    private function processingStatus(ProcessingBatch $batch): array
    {
        if ($batch->status?->value === 'verified') {
            return ['Diverifikasi', 'emerald'];
        }

        return match ($batch->state?->value) {
            'completed' => ['Selesai', 'sky'],
            'in_progress' => ['Berjalan', 'amber'],
            'cancelled' => ['Dibatalkan', 'rose'],
            default => ['Belum Mulai', 'slate'],
        };
    }

    /** @return array{0: string, 1: string} */
    private function handoverStatus(ProcessingBatch $batch): array
    {
        if ($batch->portioning_received_at) {
            return ['Diterima Pemorsian', 'emerald'];
        }
        if ($batch->portioning_handed_over_at) {
            return ['Sudah Diserahkan', 'sky'];
        }
        if ($batch->state?->value === 'completed') {
            return ['Siap Diserahkan', 'amber'];
        }

        return ['Belum Diserahkan', 'slate'];
    }

    private function quantityLabel(mixed $quantity, ?string $unit): string
    {
        $value = (float) ($quantity ?? 0);
        if ($value <= 0) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',').' '.($unit ?: '-');
    }

    private function kilogramLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',').' kg';
    }

    /**
     * @param  array<int, array{quantity: float, unit: string}>  $quantities
     * @return array{0: string, 1: string}
     */
    private function summarizeQuantities(array $quantities): array
    {
        $groups = collect($quantities)
            ->filter(fn (array $row): bool => $row['quantity'] > 0)
            ->groupBy(fn (array $row): string => str($row['unit'] ?: '-')->lower()->trim()->toString())
            ->map(function ($rows): array {
                $first = $rows->first();

                return [
                    'quantity' => (float) $rows->sum('quantity'),
                    'unit' => $first['unit'] ?: '-',
                ];
            })
            ->values();

        if ($groups->isEmpty()) {
            return ['0', 'Belum ada hasil produksi tercatat'];
        }

        $labels = $groups
            ->map(fn (array $row): string => $this->quantityLabel($row['quantity'], $row['unit']))
            ->all();

        if ($groups->count() === 1) {
            return [$labels[0], 'Total hasil akhir seluruh batch'];
        }

        return [$groups->count().' satuan', implode(' · ', $labels)];
    }

    /** @param Builder<Model> $query
     * @param  array<int, string>  $completedStates
     * @return array<string, mixed>
     */
    private function stageFromOperationalQuery(string $label, string $icon, Builder $query, array $completedStates, string $url, string $permission): array
    {
        $total = (clone $query)->count();
        $completed = (clone $query)->whereIn('state', $completedStates)->count();
        $verified = (clone $query)->where('status', 'verified')->count();

        return $this->stage($label, $icon, $total, $completed, $verified, $url, $permission);
    }

    /** @return array<string, mixed> */
    private function stage(string $label, string $icon, int $total, int $completed, int $verified, string $url, string $permission): array
    {
        if ($total === 0) {
            $state = 'not_started';
            $status = 'Belum Mulai';
            $tone = 'slate';
        } elseif ($verified >= $total) {
            $state = 'verified';
            $status = 'Diverifikasi';
            $tone = 'emerald';
        } elseif ($completed >= $total) {
            $state = 'completed';
            $status = 'Selesai';
            $tone = 'sky';
        } else {
            $state = 'running';
            $status = 'Berjalan';
            $tone = 'amber';
        }

        return compact('label', 'icon', 'total', 'completed', 'verified', 'state', 'status', 'tone', 'url', 'permission');
    }

    /** @return array<string, mixed> */
    private function card(string $label, int|float|string $value, string $detail, string $icon, string $tone, ?string $url = null, ?string $permission = null): array
    {
        return compact('label', 'value', 'detail', 'icon', 'tone', 'url', 'permission');
    }

    /** @return array<string, mixed> */
    private function miniCard(string $label, int|float|string $value, string $detail, string $icon, string $tone): array
    {
        return compact('label', 'value', 'detail', 'icon', 'tone');
    }

    private function divisionLabel(?string $code): string
    {
        return match ($code) {
            'preparation', 'persiapan' => 'Persiapan',
            'processing', 'pengolahan' => 'Pengolahan',
            'portioning', 'pemorsian' => 'Pemorsian',
            'distribution', 'distribusi' => 'Distribusi',
            'washing', 'pencucian' => 'Pencucian',
            'cleaning', 'kebersihan' => 'Kebersihan',
            null, '' => '-',
            default => str($code)->replace('_', ' ')->title()->toString(),
        };
    }
}
