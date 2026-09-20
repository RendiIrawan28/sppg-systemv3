<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\Mobile\OperationalApprovalNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationSessionService
{
    public function __construct(
        private readonly PreparationUnitConversionService $preparationUnits,
    ) {}

    public function createFromWithdrawal(WarehouseWithdrawal $withdrawal): ?PreparationSession
    {
        $withdrawal->loadMissing('items.ingredient.measurementUnit');

        if ($withdrawal->division_code !== 'persiapan'
            || ! in_array($withdrawal->status, [WarehouseWithdrawal::WAITING, WarehouseWithdrawal::VERIFIED], true)) {
            return null;
        }

        return DB::transaction(function () use ($withdrawal) {
            $session = PreparationSession::firstOrCreate(['warehouse_withdrawal_id' => $withdrawal->id], [
                'sppg_unit_id' => $withdrawal->sppg_unit_id, 'session_number' => 'PS/'.$withdrawal->withdrawal_number,
                'preparation_date' => $withdrawal->withdrawal_date, 'purpose_reference' => $withdrawal->purpose_reference, 'state' => 'planned', 'status' => 'draft', 'petugas_id' => $withdrawal->taken_by,
            ]);
            foreach ($withdrawal->items as $item) {
                $quantity = $item->actual_quantity ?? $item->requested_quantity ?? $item->verified_quantity_kg ?? $item->taken_quantity_kg;
                $sourceUnit = $item->unit_snapshot ?? 'kg';
                $resultUnit = $this->preparationUnits->defaultResultUnit($sourceUnit);
                $knownWeightKg = (float) ($item->verified_quantity_kg ?? 0);
                if ($knownWeightKg <= 0) {
                    $knownWeightKg = (float) ($item->taken_quantity_kg ?? 0);
                }

                $sessionItem = $session->items()->updateOrCreate([
                    'warehouse_withdrawal_item_id' => $item->id,
                ], [
                    'ingredient_id' => $item->ingredient_id, 'inventory_lot_id' => $item->inventory_lot_id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $sourceUnit,
                    'received_quantity' => $quantity,
                    'processed_unit_snapshot' => $resultUnit,
                    'waste_unit_snapshot' => $resultUnit,
                    'received_weight_kg' => $knownWeightKg,
                ]);

                if ((float) $sessionItem->received_weight_kg <= 0) {
                    $inferredWeightKg = $this->preparationUnits->inferSourceWeightKg($sessionItem, (float) $quantity);
                    if ($inferredWeightKg !== null && $inferredWeightKg > 0) {
                        $sessionItem->update(['received_weight_kg' => $inferredWeightKg]);
                    }
                }
            }

            return $session->refresh();
        });
    }

    public function start(PreparationSession $session, User $actor): void
    {
        abort_unless($actor->can('preparation.update'), 403);
        DB::transaction(function () use ($session, $actor): void {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->state !== 'planned') {
                throw ValidationException::withMessages(['state' => 'Sesi tidak dapat dimulai.']);
            }

            $fromState = $session->state;
            $session->update([
                'state' => 'in_progress',
                'petugas_id' => $actor->id,
                'started_at' => now(),
            ]);
            $this->history($session, $actor, 'started', $fromState, 'in_progress');
        });
    }

    public function complete(PreparationSession $session, User $actor): void
    {
        abort_unless($actor->can('preparation.update'), 403);
        DB::transaction(function () use ($session, $actor): void {
            $session = PreparationSession::query()->lockForUpdate()->with('items.returns', 'items.resultDocumentation', 'withdrawal')->findOrFail($session->id);
            if ($session->state !== 'in_progress') {
                throw ValidationException::withMessages(['state' => 'Sesi belum dikerjakan.']);
            }
            foreach ($session->items as $item) {
                $item = $this->preparationUnits->normalizeItem($item);
                $receivedQuantity = (float) ($item->received_quantity ?? 0);
                $receivedKg = (float) ($item->received_weight_kg ?? 0);
                if ($receivedQuantity > 0 && $receivedKg <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "Bobot diterima aktual (kg) untuk {$item->ingredient_name_snapshot} wajib diisi karena bahan diterima dalam satuan {$item->unit_snapshot} dan belum memiliki konversi bobot.",
                    ]);
                }

                $cleanKg = (float) ($item->clean_weight_kg ?? 0);
                $wasteKg = (float) ($item->waste_weight_kg ?? 0);
                $returnedQuantity = (float) $item->returns->where('status', PreparationReturn::VERIFIED)->sum('actual_quantity');
                $returnedQuantity += (float) $item->returns->where('status', PreparationReturn::WAITING)->sum('requested_quantity');
                $returnedKg = $returnedQuantity > 0
                    ? $this->preparationUnits->sourceQuantityToKg($item, $returnedQuantity)
                    : 0.0;

                if ($cleanKg < 0 || $wasteKg < 0 || abs(($cleanKg + $wasteKg + $returnedKg) - $receivedKg) > 0.01) {
                    throw ValidationException::withMessages([
                        'items' => sprintf(
                            'Rekonsiliasi %s belum sesuai. Diterima %.3f kg, hasil %.3f kg, limbah %.3f kg, retur %.3f kg. Total hasil + limbah + retur harus sama dengan bobot diterima.',
                            $item->ingredient_name_snapshot,
                            $receivedKg,
                            $cleanKg,
                            $wasteKg,
                            $returnedKg,
                        ),
                    ]);
                }
            }

            if ($session->items->contains(fn ($item): bool => (float) $item->processed_quantity > 0 && ! $item->resultDocumentation)) {
                throw ValidationException::withMessages(['documentations' => 'Setiap bahan yang menghasilkan Hasil Siap wajib memiliki foto.']);
            }

            $session->update(['state' => 'completed', 'completed_at' => now()]);
            $this->history($session, $actor, 'completed', 'in_progress', 'completed');
        });
    }

    public function submit(PreparationSession $session, User $actor): void
    {
        abort_unless($actor->can('preparation.submit'), 403);
        DB::transaction(function () use ($session, $actor): void {
            $session = PreparationSession::query()->with(['items', 'wasteHandoverReport'])->lockForUpdate()->findOrFail($session->id);
            $hasWaste = $session->items->sum(fn ($item): float => (float) ($item->waste_quantity ?? $item->waste_weight_kg)) > 0;
            if ($hasWaste && ! $session->wasteHandoverReport) {
                throw ValidationException::withMessages(['waste' => 'Berita acara limbah otomatis belum tersedia. Simpan data Persiapan terlebih dahulu.']);
            }
            if ($session->state !== 'completed'
                || ! in_array($session->status, [OperationalReportStatus::Draft, OperationalReportStatus::RevisionRequired], true)) {
                throw ValidationException::withMessages(['status' => 'Laporan hanya dapat diajukan setelah Persiapan selesai.']);
            }
            $fromStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'review_notes' => null,
            ]);
            $this->history($session, $actor, 'submitted', null, null, $fromStatus, OperationalReportStatus::Submitted->value);
            app(OperationalApprovalNotificationService::class)->submitted(
                $session->refresh(), 'preparation', 'Persiapan', 'persiapan', $session->session_number,
            );
        });
    }

    public function approve(PreparationSession $session, User $actor, ?string $notes = null): void
    {
        abort_unless($actor->can('preparation.approve'), 403);
        DB::transaction(function () use ($session, $actor, $notes): void {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->id);
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($session->status, $actor);
            $fromStatus = $session->status->value;
            $updates = ['status' => $nextStatus, 'review_notes' => filled($notes) ? trim($notes) : null];
            if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                $updates += ['division_approved_by' => $actor->id, 'division_approved_at' => now()];
            } else {
                $updates += ['verified_by' => $actor->id, 'verified_at' => now()];
            }
            $session->update($updates);
            $this->history($session, $actor, app(OperationalReportApprovalService::class)->reviewActionName($nextStatus), null, null, $fromStatus, $nextStatus->value, $notes);
            app(OperationalApprovalNotificationService::class)->reviewed(
                $session->refresh(), $nextStatus, 'preparation', 'Persiapan', $session->session_number,
            );
        });
    }

    public function requestRevision(PreparationSession $session, User $actor, string $notes): void
    {
        abort_unless($actor->can('preparation.approve'), 403);
        DB::transaction(function () use ($session, $actor, $notes): void {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->id);
            if (blank($notes)) {
                throw ValidationException::withMessages(['reviewNotes' => 'Alasan revisi wajib diisi pada tahap pemeriksaan.']);
            }
            app(OperationalReportApprovalService::class)->assertCanReviewStage($session->status, $actor);
            $fromStatus = $session->status->value;
            $session->update(['status' => OperationalReportStatus::RevisionRequired, 'review_notes' => trim($notes)]);
            $this->history($session, $actor, 'revision_requested', null, null, $fromStatus, OperationalReportStatus::RevisionRequired->value, $notes);
            app(OperationalApprovalNotificationService::class)->revisionRequired(
                $session->refresh(), 'preparation', 'Persiapan', $session->session_number,
            );
        });
    }

    private function history(
        PreparationSession $session,
        User $actor,
        string $action,
        ?string $fromState = null,
        ?string $toState = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $notes = null,
    ): void {
        $session->histories()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $session->fresh()->toArray(),
        ]);
    }
}
