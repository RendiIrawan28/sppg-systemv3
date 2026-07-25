<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationSessionService
{
    public function createFromWithdrawal(WarehouseWithdrawal $withdrawal): ?PreparationSession
    {
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
                $session->items()->updateOrCreate([
                    'warehouse_withdrawal_item_id' => $item->id,
                ], [
                    'ingredient_id' => $item->ingredient_id, 'inventory_lot_id' => $item->inventory_lot_id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $item->unit_snapshot ?? 'kg',
                    'received_quantity' => $quantity,
                    'received_weight_kg' => ($item->unit_snapshot ?? 'kg') === 'kg'
                        ? $quantity
                        : 0,
                ]);
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
            $session = PreparationSession::query()->lockForUpdate()->with('items.returns', 'resultDocumentation', 'withdrawal')->findOrFail($session->id);
            if ($session->state !== 'in_progress') {
                throw ValidationException::withMessages(['state' => 'Sesi belum dikerjakan.']);
            }
            if ($session->withdrawal?->status !== WarehouseWithdrawal::VERIFIED) {
                throw ValidationException::withMessages(['items' => 'Pekerjaan dapat dimulai, tetapi belum dapat diselesaikan sampai jumlah pengambilan diverifikasi Gudang.']);
            }

            foreach ($session->items as $item) {
                $received = (float) ($item->received_quantity ?? $item->received_weight_kg);
                $clean = (float) ($item->processed_quantity ?? $item->clean_weight_kg);
                $waste = (float) ($item->waste_quantity ?? $item->waste_weight_kg);
                if ($item->returns->where('status', PreparationReturn::WAITING)->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => "Retur {$item->ingredient_name_snapshot} masih menunggu verifikasi Gudang."]);
                }
                $returned = (float) $item->returns->where('status', PreparationReturn::VERIFIED)->sum('actual_quantity');
                if ($clean < 0 || $waste < 0 || abs(($clean + $waste + $returned) - $received) > 0.01) {
                    throw ValidationException::withMessages(['items' => "Jumlah hasil + sisa + retur {$item->ingredient_name_snapshot} harus sama dengan jumlah diterima."]);
                }
            }

            if (! $session->resultDocumentation) {
                throw ValidationException::withMessages(['documentations' => 'Foto hasil Persiapan wajib tersedia.']);
            }

            $session->update(['state' => 'completed', 'completed_at' => now()]);
            $this->history($session, $actor, 'completed', 'in_progress', 'completed');
        });
    }

    public function submit(PreparationSession $session, User $actor): void
    {
        abort_unless($actor->can('preparation.submit'), 403);
        DB::transaction(function () use ($session, $actor): void {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->id);
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
