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

        return compact('overview', 'progress', 'warehouse');
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
