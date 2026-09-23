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
    use ReadsFinalMonitoring;

    /** Load only the selected detail; the landing uses summaryFor(). */
    public function forTab(SppgUnit $unit, string $date, string $tab): array
    {
        $date = Carbon::parse($date)->toDateString();
        if (in_array($tab, ['washing', 'cleaning', 'field-assistant', 'attendance'], true)) {
            return ['finalModule' => $this->finalModule((int) $unit->id, $date, $tab)];
        }
        if (in_array($tab, ['warehouse', 'preparation', 'processing', 'portioning', 'distribution'], true)) {
            return [$tab => $this->{$tab}((int) $unit->id, $date)];
        }

        return $this->summaryFor($unit, $date);
    }
    /** @return array<string, mixed> */
    public function for(SppgUnit $unit, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $unitId = (int) $unit->getKey();

        $summary = $this->summaryFor($unit, $date);
        $warehouse = $this->warehouse($unitId, $date);
        $preparation = $this->preparation($unitId, $date);
        $processing = $this->processing($unitId, $date);
        $portioning = $this->portioning($unitId, $date);
        $distribution = $this->distribution($unitId, $date);

        return [...$summary, ...compact('warehouse', 'preparation', 'processing', 'portioning', 'distribution')];
    }

    /**
     * @return array{
     *     overview: array<string, mixed>,
     *     progress: array<int, array<string, mixed>>,
     *     moduleSummaries: array<string, array<string, string>>
     * }
     */
    public function summaryFor(SppgUnit $unit, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $unitId = (int) $unit->getKey();
        $progress = $this->progress($unitId, $date);
        $overview = $this->overview($unitId, $date, $progress);
        $moduleSummaries = $this->moduleSummaries($unitId, $date);
        foreach (['washing', 'cleaning', 'field-assistant', 'attendance'] as $tab) {
            $cards = $this->finalSummary($unitId, $date, $tab);
            $moduleSummaries[$tab] = ['primary' => $cards[0]['value'].' '.$cards[0]['label'], 'secondary' => $cards[1]['value'].' '.$cards[1]['label']];
        }

        return compact('overview', 'progress', 'moduleSummaries');
    }

    /** @return array<string, array<string, string>> */
    private function moduleSummaries(int $unitId, string $date): array
    {
        $portioning = PortioningSession::query()
            ->where('sppg_unit_id', $unitId)
            ->forDate($date)
            ->selectRaw('COUNT(*) as session_count')
            ->selectRaw('COALESCE(SUM(COALESCE(target_small_portions, 0) + COALESCE(target_large_portions, 0)), 0) as target_total')
            ->selectRaw('COALESCE(SUM(COALESCE(actual_small_portions, 0) + COALESCE(actual_large_portions, 0)), 0) as actual_total')
            ->first();

        $distribution = DistributionRun::query()
            ->where('sppg_unit_id', $unitId)
            ->forDate($date)
            ->selectRaw('COUNT(*) as route_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN state IN ('destinations_completed', 'returned') THEN 1 ELSE 0 END), 0) as completed_count")
            ->first();

        return [
            'portioning' => [
                'primary' => number_format((int) ($portioning?->session_count ?? 0), 0, ',', '.').' sesi',
                'secondary' => number_format((int) ($portioning?->actual_total ?? 0), 0, ',', '.').' / '.number_format((int) ($portioning?->target_total ?? 0), 0, ',', '.').' porsi',
            ],
            'distribution' => [
                'primary' => number_format((int) ($distribution?->route_count ?? 0), 0, ',', '.').' rute',
                'secondary' => number_format((int) ($distribution?->completed_count ?? 0), 0, ',', '.').' selesai',
            ],
        ];
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
        })->values()->all();

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

    /** @return array<string, mixed> */
    private function portioning(int $unitId, string $date): array
    {
        $sessions = PortioningSession::query()
            ->with(['routeAllocations', 'routeRecords', 'leftoverRecords', 'petugas'])
            ->where('sppg_unit_id', $unitId)
            ->forDate($date)
            ->latest('started_at')
            ->latest('id')
            ->get();

        $leftovers = $sessions->flatMap(fn (PortioningSession $session) => $session->leftoverRecords);
        [, $leftoverDetail] = $this->summarizeQuantities(
            $leftovers->map(fn ($leftover): array => [
                'quantity' => (float) ($leftover->quantity ?? 0),
                'unit' => $leftover->unit_name ?: '-',
            ])->all(),
        );

        $rows = $sessions->map(function (PortioningSession $session): array {
            [$processStatus, $processTone] = $this->portioningStatus($session);
            [$reportStatus, $reportTone] = $this->reportStatus($session->status);

            return [
                'number' => $session->session_number ?: '-',
                'menu' => $session->menu_name_snapshot ?: '-',
                'target_small' => (int) $session->target_small_portions,
                'target_large' => (int) $session->target_large_portions,
                'target_total' => $session->target_total,
                'actual_small' => (int) $session->actual_small_portions,
                'actual_large' => (int) $session->actual_large_portions,
                'actual_total' => $session->actual_total,
                'started_at' => $session->started_at?->format('H:i') ?? '-',
                'completed_at' => $session->completed_at?->format('H:i') ?? '-',
                'officer' => $session->petugas?->name ?: $session->petugas_name_snapshot ?: '-',
                'process_status' => $processStatus,
                'process_tone' => $processTone,
                'report_status' => $reportStatus,
                'report_tone' => $reportTone,
                'allocations' => $session->routeAllocations->map(fn ($allocation): array => [
                    'route' => $allocation->route_name ?: '-',
                    'destination' => $allocation->destination_name ?: '-',
                    'target_small' => (int) $allocation->target_small_portions,
                    'target_large' => (int) $allocation->target_large_portions,
                    'planned_at' => $allocation->planned_arrival_at?->format('H:i') ?? '-',
                ])->values()->all(),
                'route_records' => $session->routeRecords->map(fn ($record): array => [
                    'route' => $record->route_name ?: '-',
                    'actual_small' => (int) $record->small_portions,
                    'actual_large' => (int) $record->large_portions,
                    'completed_at' => $record->completed_at?->format('H:i') ?? '-',
                    'notes' => $record->notes ?: null,
                    'photo_url' => $record->photo_path ? Storage::disk('public')->url($record->photo_path) : null,
                ])->values()->all(),
            ];
        })->values()->all();

        $leftoverRows = $sessions->flatMap(fn (PortioningSession $session): array => $session->leftoverRecords
            ->map(fn ($leftover): array => [
                'session' => $session->session_number ?: '-',
                'time' => $leftover->checked_at?->format('H:i') ?? '-',
                'food_type' => $leftover->food_type ?: '-',
                'quantity' => $this->numberLabel($leftover->quantity),
                'unit' => $leftover->unit_name ?: '-',
                'notes' => $leftover->notes ?: '-',
                'photo_url' => $leftover->photo_path ? Storage::disk('public')->url($leftover->photo_path) : null,
            ])->all())
            ->values()
            ->all();

        return [
            'cards' => [
                $this->miniCard('Target Porsi', (int) $sessions->sum(fn (PortioningSession $session): int => $session->target_total), 'Porsi kecil + besar yang ditargetkan', 'calculator', 'sky'),
                $this->miniCard('Porsi Aktual', (int) $sessions->sum(fn (PortioningSession $session): int => $session->actual_total), 'Porsi kecil + besar yang selesai', 'check-badge', 'emerald'),
                $this->miniCard('Rute Pemorsian Selesai', $sessions->sum(fn (PortioningSession $session): int => $session->routeRecords->count()), 'Jumlah pencatatan rute yang selesai', 'route', 'violet'),
                $this->miniCard('Sisa Makanan', $leftovers->count(), $leftovers->isEmpty() ? 'Tidak ada data sisa makanan' : $leftoverDetail, 'recycle', $leftovers->isEmpty() ? 'slate' : 'amber'),
            ],
            'rows' => $rows,
            'leftovers' => $leftoverRows,
            'detail_url' => route('v3.portioning.index', ['tanggal' => $date]),
        ];
    }

    /** @return array<string, mixed> */
    private function distribution(int $unitId, string $date): array
    {
        $runs = DistributionRun::query()
            ->with(['stops', 'documentations', 'petugas'])
            ->where('sppg_unit_id', $unitId)
            ->forDate($date)
            ->latest('actual_departure_at')
            ->latest('id')
            ->get();

        $completed = $runs->filter(fn (DistributionRun $run): bool => in_array($run->state?->value, ['destinations_completed', 'returned'], true))->count();

        $rows = $runs->map(function (DistributionRun $run): array {
            [$processStatus, $processTone] = $this->distributionStatus($run);
            [$reportStatus, $reportTone] = $this->reportStatus($run->status);

            return [
                'number' => $run->run_number ?: '-',
                'route' => $run->route_name ?: '-',
                'menu' => $run->menu_name_snapshot ?: '-',
                'small' => (int) $run->loaded_small_portions,
                'large' => (int) $run->loaded_large_portions,
                'total' => $run->loaded_total,
                'departed_at' => $run->actual_departure_at?->format('H:i') ?? '-',
                'destinations_completed_at' => $run->destinations_completed_at?->format('H:i') ?? '-',
                'returned_at' => $run->returned_at?->format('H:i') ?? '-',
                'officer' => $run->petugas?->name ?: $run->petugas_name_snapshot ?: '-',
                'driver' => $run->driver_name ?: '-',
                'vehicle' => collect([$run->vehicle_name, $run->vehicle_plate])->filter()->implode(' · ') ?: '-',
                'process_status' => $processStatus,
                'process_tone' => $processTone,
                'report_status' => $reportStatus,
                'report_tone' => $reportTone,
                'return_summary' => [
                    'portions' => $run->returned_total,
                    'containers' => (int) $run->containers_returned,
                    'damaged' => (int) $run->containers_damaged,
                    'lost' => (int) $run->containers_lost,
                ],
                'stops' => $run->stops->map(function ($stop): array {
                    $deliveredSmall = (int) $stop->delivered_small_portions;
                    $deliveredLarge = (int) $stop->delivered_large_portions;
                    $hasActual = $stop->status?->isTerminal() ?? false;

                    return [
                        'sequence' => (int) $stop->sequence_order,
                        'destination' => $stop->destination_name ?: '-',
                        'type' => $stop->destination_type ? str($stop->destination_type)->replace('_', ' ')->title()->toString() : '-',
                        'planned_small' => (int) $stop->small_portions,
                        'planned_large' => (int) $stop->large_portions,
                        'planned_total' => (int) $stop->small_portions + (int) $stop->large_portions,
                        'actual_small' => $hasActual ? $deliveredSmall : null,
                        'actual_large' => $hasActual ? $deliveredLarge : null,
                        'actual_total' => $hasActual ? $deliveredSmall + $deliveredLarge : null,
                        'planned_at' => $stop->planned_arrival_at?->format('H:i') ?? '-',
                        'arrived_at' => $stop->arrived_at?->format('H:i') ?? '-',
                        'recipient' => collect([$stop->recipient_name, $stop->recipient_position])->filter()->implode(' · ') ?: '-',
                        'status' => $stop->status?->label() ?? '-',
                        'status_tone' => $this->distributionStopTone($stop->status?->value),
                        'photo_url' => $stop->handover_photo_path ? Storage::disk('public')->url($stop->handover_photo_path) : null,
                    ];
                })->values()->all(),
                'documentations' => $run->documentations
                    ->filter(fn ($documentation): bool => filled($documentation->photo_path))
                    ->map(fn ($documentation): array => [
                        'phase' => $documentation->phase ? str($documentation->phase)->replace('_', ' ')->title()->toString() : 'Dokumentasi',
                        'caption' => $documentation->caption ?: '-',
                        'time' => $documentation->captured_at?->format('H:i') ?? '-',
                        'photo_url' => Storage::disk('public')->url($documentation->photo_path),
                    ])->values()->all(),
            ];
        })->values()->all();

        return [
            'cards' => [
                $this->miniCard('Total Rute', $runs->count(), 'Rute distribusi pada tanggal terpilih', 'route', 'sky'),
                $this->miniCard('Rute Selesai', $completed, 'Semua tujuan selesai atau kembali ke SPPG', 'check-badge', 'emerald'),
                $this->miniCard('Porsi Dibawa', (int) $runs->sum(fn (DistributionRun $run): int => $run->loaded_total), 'Porsi kecil + besar yang dimuat', 'truck', 'violet'),
                $this->miniCard('Porsi Terkirim', (int) $runs->sum(fn (DistributionRun $run): int => $run->delivered_total), 'Porsi aktual yang diterima tujuan', 'users', 'amber'),
            ],
            'rows' => $rows,
            'detail_url' => route('v3.operations.index', ['module' => 'distribusi', 'tanggal' => $date]),
        ];
    }

    /** @param array<int, array<string, mixed>> $progress
     * @return array<string, mixed>
     */
    private function overview(int $unitId, string $date, array $progress): array
    {
        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date)
            ->get();

        $planIds = $plans->pluck('id');
        $destinationCount = $planIds->isEmpty()
            ? 0
            : FieldDistributionPlanDestination::query()
                ->whereIn('field_distribution_plan_id', $planIds)
                ->count();

        $confirmedRecipients = FieldDistributionPlanDestination::query()->whereIn('field_distribution_plan_id', $planIds)
            ->whereIn('confirmation_status', ['confirmed', 'changed'])->sum('confirmed_beneficiaries');

        $withdrawalSummary = WarehouseWithdrawalItem::query()
            ->whereHas('withdrawal', fn (Builder $query) => $query
                ->where('sppg_unit_id', $unitId)
                ->whereDate('withdrawal_date', $date))
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(verified_quantity_kg, 0) > 0 THEN verified_quantity_kg ELSE COALESCE(taken_quantity_kg, 0) END), 0) as total_kg')
            ->first();

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

        $outKg = (float) ($withdrawalSummary?->total_kg ?? 0);
        $withdrawalItemCount = (int) ($withdrawalSummary?->total_count ?? 0);

        return [
            'cards' => [
                $this->card(
                    'Penerima Hari Ini',
                    (int) $confirmedRecipients,
                    'Penerima pada tujuan yang telah dikonfirmasi; tanggal distribusi terpilih',
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
                    'Seluruh tujuan/kunjungan, termasuk yang belum mempunyai rute',
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
                    $withdrawalItemCount,
                    $outKg > 0
                        ? number_format($outKg, 2, ',', '.').' kg tercatat pada '.$withdrawalItemCount.' baris bahan'
                        : $withdrawalItemCount.' baris bahan diambil dari gudang',
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
            ->whereDate('distribution_date', $date);

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
    private function portioningStatus(PortioningSession $session): array
    {
        return [
            $session->state?->label() ?? 'Belum Mulai',
            match ($session->state?->value) {
                'completed' => 'emerald',
                'in_progress' => 'amber',
                'cancelled' => 'rose',
                default => 'slate',
            },
        ];
    }

    /** @return array{0: string, 1: string} */
    private function distributionStatus(DistributionRun $run): array
    {
        return [
            $run->state?->label() ?? 'Belum Mulai',
            match ($run->state?->value) {
                'returned' => 'emerald',
                'destinations_completed', 'departed' => 'amber',
                'assigned', 'loaded' => 'sky',
                'cancelled' => 'rose',
                default => 'slate',
            },
        ];
    }

    /** @return array{0: string, 1: string} */
    private function reportStatus(mixed $status): array
    {
        return [
            $status?->label() ?? 'Draft',
            match ($status?->value) {
                'verified' => 'emerald',
                'division_approved' => 'sky',
                'submitted' => 'amber',
                'revision_required' => 'rose',
                default => 'slate',
            },
        ];
    }

    private function distributionStopTone(?string $status): string
    {
        return match ($status) {
            'delivered' => 'emerald',
            'arrived', 'partial' => 'sky',
            'in_transit' => 'amber',
            'failed' => 'rose',
            default => 'slate',
        };
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

    private function numberLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
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
