<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use App\Enums\UserRole;
use App\Models\DistributionRun;
use App\Models\PortioningSession;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortioningWorkflow
{
    public function start(PortioningSession $session, User $actor): PortioningSession
    {
        abort_unless($actor->can('portioning.update'), 403);

        return DB::transaction(function () use ($session, $actor): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->isReportEditable() || $session->state !== PortioningSessionState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Sesi pemorsian tidak dapat dimulai pada kondisi saat ini.',
                ]);
            }
            if ($session->routeAllocations->isEmpty()) {
                throw ValidationException::withMessages(['routeAllocations' => 'Pembagian rute belum tersedia.']);
            }
            $previousState = $session->state->value;
            $session->update([
                'state' => PortioningSessionState::InProgress,
                'started_at' => $session->started_at ?: now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'started', $previousState, $session->state->value);

            return $session->refresh();
        });
    }

    public function complete(PortioningSession $session, User $actor): PortioningSession
    {
        abort_unless($actor->can('portioning.update'), 403);

        return DB::transaction(function () use ($session, $actor): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->isReportEditable() || $session->state !== PortioningSessionState::InProgress) {
                throw ValidationException::withMessages([
                    'state' => 'Sesi pemorsian tidak sedang dikerjakan.',
                ]);
            }
            $session->recalculateTotals();
            $session = $this->lockedSession($session);
            $this->validateBeforeComplete($session);

            $previousState = $session->state->value;
            $session->update([
                'state' => PortioningSessionState::Completed,
                'completed_at' => now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'completed', $previousState, $session->state->value);
            $this->syncDistributionRun($session, $actor);

            return $session->refresh();
        });
    }

    public function cancel(PortioningSession $session, User $actor, string $reason): PortioningSession
    {
        abort_unless($actor->can('portioning.update'), 403);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($session, $actor, $reason): PortioningSession {
            $session = $this->lockedSession($session);
            if (! $this->canCancel($session)) {
                throw ValidationException::withMessages([
                    'state' => 'Pemorsian tidak dapat dibatalkan karena sudah memiliki barang, hasil rute, atau sisa makanan.',
                ]);
            }

            $previousState = $session->state->value;
            $reason = trim($reason);
            $session->update([
                'state' => PortioningSessionState::Cancelled,
                'completed_at' => now(),
                'notes' => filled($session->notes)
                    ? trim((string) $session->notes)."\n\nPembatalan: {$reason}"
                    : "Pembatalan: {$reason}",
                'updated_by' => $actor->getKey(),
            ]);
            $this->writeHistory(
                $session,
                $actor,
                'portioning_cancelled',
                $previousState,
                PortioningSessionState::Cancelled->value,
                $reason,
            );

            return $session->refresh();
        });
    }

    public function canCancel(PortioningSession $session): bool
    {
        if (! in_array($session->state, [PortioningSessionState::Planned, PortioningSessionState::InProgress], true)
            || ! $session->isReportEditable()) {
            return false;
        }

        return ! $session->supplies()->exists()
            && ! $session->preparationOutputWithdrawals()->exists()
            && ! $session->routeRecords()->exists()
            && ! $session->leftoverRecords()->exists()
            && ! WarehouseWithdrawal::query()
                ->where('division_code', 'pemorsian')
                ->where('reference_type', 'portioning_session')
                ->where('reference_id', $session->getKey())
                ->whereHas('items')
                ->exists();
    }

    private function syncDistributionRun(
        PortioningSession $session,
        User $actor,
    ): void {
        $run = DistributionRun::query()
            ->where('portioning_session_id', $session->getKey())
            ->lockForUpdate()
            ->first();

        if (! $run) {
            return;
        }

        $run->update(['updated_by' => $actor->getKey()]);
    }

    public function submit(PortioningSession $session, User $actor, ?string $notes = null): PortioningSession
    {
        abort_unless($actor->can('portioning.submit'), 403);

        return DB::transaction(function () use ($session, $actor, $notes): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Sesi belum dapat diajukan. Selesaikan Pemorsian terlebih dahulu.',
                ]);
            }

            $issues = $this->submissionIssues($session);
            if ($issues !== []) {
                throw ValidationException::withMessages([
                    'readiness' => implode(' ', $issues),
                ]);
            }

            $previousStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'division_approved_by' => null,
                'division_approved_at' => null,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'submitted',
                $session->state->value,
                $session->state->value,
                $notes,
                $previousStatus,
                $session->status->value,
            );

            return $session->refresh();
        });
    }

    public function verify(PortioningSession $session, User $actor, ?string $notes = null): PortioningSession
    {
        abort_unless($actor->can('portioning.approve'), 403);

        return DB::transaction(function () use ($session, $actor, $notes): PortioningSession {
            $session = $this->lockedSession($session);

            if (! app(OperationalReportApprovalService::class)->isReviewable($session->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $this->assertCanReviewStage($session->status, $actor);
            $previousStatus = $session->status->value;
            $nextStatus = $session->status === OperationalReportStatus::Submitted
                ? OperationalReportStatus::DivisionApproved
                : OperationalReportStatus::Verified;
            $action = $nextStatus === OperationalReportStatus::DivisionApproved
                ? 'division_approved'
                : 'head_verified';

            $updates = [
                'status' => $nextStatus,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ];
            if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                $updates += ['division_approved_by' => $actor->getKey(), 'division_approved_at' => now()];
            } else {
                $updates += ['verified_by' => $actor->getKey(), 'verified_at' => now()];
            }
            $session->update($updates);

            $this->writeHistory(
                $session,
                $actor,
                $action,
                $session->state->value,
                $session->state->value,
                $notes,
                $previousStatus,
                $nextStatus->value,
            );

            return $session->refresh();
        });
    }

    public function requestRevision(PortioningSession $session, User $actor, string $notes): PortioningSession
    {
        abort_unless($actor->can('portioning.approve'), 403);

        return DB::transaction(function () use ($session, $actor, $notes): PortioningSession {
            $session = $this->lockedSession($session);

            $this->assertCanReviewStage($session->status, $actor);
            if (blank($notes)) {
                throw ValidationException::withMessages(['reviewNotes' => 'Alasan revisi wajib diisi.']);
            }

            $previousStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'revision_requested',
                $session->state->value,
                $session->state->value,
                $notes,
                $previousStatus,
                $session->status->value,
            );

            return $session->refresh();
        });
    }

    public function submissionIssues(PortioningSession $session): array
    {
        $session = PortioningSession::query()
            ->with(['routeRecords', 'leftoverRecords'])
            ->findOrFail($session->getKey());

        $issues = [];

        if (! in_array($session->state, [
            PortioningSessionState::Completed,
        ], true)) {
            $issues[] = 'Pemorsian belum diselesaikan.';
        }

        if ($session->routeRecords->isEmpty()) {
            $issues[] = 'Minimal satu rute Pemorsian wajib disimpan.';
        }

        if ($session->routeRecords->contains(
            fn ($route): bool => (int) $route->small_portions + (int) $route->large_portions <= 0
                || blank($route->photo_path)
                || $route->completed_at === null,
        )) {
            $issues[] = 'Jumlah, dokumentasi, dan waktu setiap rute wajib lengkap.';
        }

        $hasVariance = (int) $session->actual_small_portions !== (int) $session->target_small_portions
            || (int) $session->actual_large_portions !== (int) $session->target_large_portions;
        if ($hasVariance && ! $this->hasMeaningfulSessionNotes($session)) {
            $issues[] = 'Catatan wajib diisi karena total Pemorsian belum sesuai target harian.';
        }

        if (! in_array($session->leftover_mode, ['none', 'present'], true)) {
            $issues[] = 'Status sisa makanan belum dipilih.';
        } elseif ($session->leftover_mode === 'present') {
            if ($session->leftoverRecords->isEmpty()) {
                $issues[] = 'Data sisa makanan wajib diisi.';
            } elseif ($session->leftoverRecords->contains(
                fn ($leftover): bool => (float) $leftover->quantity <= 0
                    || blank($leftover->unit_name)
                    || blank($leftover->photo_path),
            )) {
                $issues[] = 'Jumlah, satuan, dan foto setiap sisa makanan wajib diisi.';
            }
        }

        return $issues;
    }

    private function assertCanReviewStage(OperationalReportStatus $status, User $actor): void
    {
        if ($status === OperationalReportStatus::Submitted
            && ! $actor->is_super_admin
            && ! $actor->hasRole(UserRole::KepalaDivisiPemorsian->value)) {
            throw ValidationException::withMessages([
                'status' => 'Laporan harus diperiksa Kepala Divisi Pemorsian terlebih dahulu.',
            ]);
        }

        if ($status === OperationalReportStatus::DivisionApproved
            && ! $actor->is_super_admin
            && ! $actor->hasRole(UserRole::KepalaSppg->value)) {
            throw ValidationException::withMessages([
                'status' => 'Hanya Kepala SPPG yang dapat melakukan verifikasi akhir laporan Pemorsian.',
            ]);
        }
    }

    private function validateBeforeComplete(PortioningSession $session): void
    {
        $errors = [];

        if (! $this->hasMaterialInput($session)) {
            $errors['supplies'] = 'Minimal satu barang dari Gudang atau hasil Persiapan harus tersedia sebelum Pemorsian diselesaikan.';
        }

        if ($session->routeRecords->isEmpty()) {
            $errors['routeRecords'] = 'Minimal satu rute Pemorsian wajib disimpan.';
        }

        if ($session->actual_total <= 0) {
            $errors['routeAllocations'] = 'Jumlah realisasi porsi harus lebih dari nol.';
        }

        if ($session->routeRecords->contains(
            fn ($route): bool => (int) $route->small_portions + (int) $route->large_portions <= 0
                || blank($route->photo_path)
                || $route->completed_at === null,
        )) {
            $errors['routeRecords'] = 'Jumlah, dokumentasi, dan waktu setiap rute wajib lengkap.';
        }

        $hasVariance = (int) $session->actual_small_portions !== (int) $session->target_small_portions
            || (int) $session->actual_large_portions !== (int) $session->target_large_portions;
        if ($hasVariance && ! $this->hasMeaningfulSessionNotes($session)) {
            $errors['notes'] = 'Catatan wajib diisi karena total Pemorsian belum sesuai target harian.';
        }

        if (! in_array($session->leftover_mode, ['none', 'present'], true)) {
            $errors['leftovers'] = 'Pilih apakah terdapat sisa makanan.';
        } elseif ($session->leftover_mode === 'present'
            && ($session->leftoverRecords->isEmpty()
                || $session->leftoverRecords->contains(
                    fn ($leftover): bool => (float) $leftover->quantity <= 0
                        || blank($leftover->unit_name)
                        || blank($leftover->photo_path),
                ))) {
            $errors['leftovers'] = 'Lengkapi jumlah, satuan, dan foto sisa makanan.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function lockedSession(PortioningSession $session): PortioningSession
    {
        return PortioningSession::query()
            ->with([
                'routeAllocations',
                'routeRecords',
                'leftoverRecords',
                'supplies',
                'preparationOutputWithdrawals',
            ])
            ->lockForUpdate()
            ->findOrFail($session->getKey());
    }

    private function hasMaterialInput(PortioningSession $session): bool
    {
        if ($session->supplies->isNotEmpty()) {
            return true;
        }

        return $session->preparationOutputWithdrawals
            ->contains(fn ($withdrawal): bool => in_array($withdrawal->status, ['waiting_verification', 'verified'], true)
                && (float) ($withdrawal->verified_quantity ?: $withdrawal->requested_quantity) > 0);
    }

    private function hasMeaningfulSessionNotes(PortioningSession $session): bool
    {
        return filled($session->notes)
            && ! str_starts_with((string) $session->notes, 'Dibuat dari rencana distribusi ');
    }

    private function writeHistory(
        PortioningSession $session,
        User $actor,
        string $action,
        ?string $previousState,
        ?string $newState,
        ?string $notes = null,
        ?string $previousStatus = null,
        ?string $newStatus = null,
    ): void {
        $session->histories()->create([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'snapshot' => $session->load([
                'routeAllocations',
                'routeRecords',
                'leftoverRecords',
            ])->toArray(),
        ]);
    }
}
