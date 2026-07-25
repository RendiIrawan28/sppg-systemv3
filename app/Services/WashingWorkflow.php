<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\WashingDeviationSeverity;
use App\Enums\WashingDeviationStatus;
use App\Enums\WashingSessionState;
use App\Models\User;
use App\Models\WashingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WashingWorkflow
{
    public function receive(WashingSession $session, User $actor, array $data): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Planned) {
                throw ValidationException::withMessages(['state' => 'Ompreng hanya dapat diterima dari tahap Direncanakan.']);
            }

            $expected = (int) ($data['expected_containers'] ?? $session->expected_containers);
            $received = (int) ($data['received_containers'] ?? 0);
            $damaged = (int) ($data['damaged_containers'] ?? 0);
            $missing = (int) ($data['missing_containers'] ?? max(0, $expected - $received));

            if ($expected <= 0 || $received < 0 || $received > $expected) {
                throw ValidationException::withMessages(['received_containers' => 'Jumlah diterima harus berada antara 0 dan jumlah yang diharapkan.']);
            }

            if ($received + $missing !== $expected) {
                throw ValidationException::withMessages(['missing_containers' => 'Jumlah diterima + hilang harus sama dengan jumlah yang diharapkan.']);
            }

            if ($damaged > $received) {
                throw ValidationException::withMessages(['damaged_containers' => 'Jumlah rusak tidak boleh melebihi jumlah diterima.']);
            }

            $previousState = $session->state->value;
            $session->update([
                'expected_containers' => $expected,
                'received_containers' => $received,
                'damaged_containers' => $damaged,
                'missing_containers' => $missing,
                'received_at' => $data['received_at'] ?? now(),
                'state' => WashingSessionState::Received,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'received', $previousState, $session->state->value, $data['notes'] ?? null);
            return $session->refresh();
        });
    }

    public function start(WashingSession $session, User $actor, array $data): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Received) {
                throw ValidationException::withMessages(['state' => 'Pencucian hanya dapat dimulai setelah ompreng diterima.']);
            }

            if ((int) $session->received_containers <= 0) {
                throw ValidationException::withMessages(['received_containers' => 'Tidak ada ompreng yang dapat dicuci.']);
            }

            $previousState = $session->state->value;
            $session->update([
                'started_at' => $data['started_at'] ?? now(),
                'state' => WashingSessionState::Washing,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'started', $previousState, $session->state->value, $data['notes'] ?? null);
            return $session->refresh();
        });
    }

    public function complete(WashingSession $session, User $actor, array $data): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Washing) {
                throw ValidationException::withMessages(['state' => 'Pencucian hanya dapat diselesaikan dari tahap Sedang Dicuci.']);
            }

            $washed = (int) ($data['washed_containers'] ?? $session->washed_containers);
            $clean = (int) ($data['clean_containers'] ?? $session->clean_containers);
            $damaged = (int) ($data['damaged_containers'] ?? $session->damaged_containers);
            $rejected = (int) ($data['rejected_containers'] ?? $session->rejected_containers);
            $missing = (int) ($data['missing_containers'] ?? $session->missing_containers);

            if ((int) $session->expected_containers !== (int) $session->received_containers + $missing) {
                throw ValidationException::withMessages(['missing_containers' => 'Jumlah diterima + hilang harus sama dengan jumlah yang diharapkan.']);
            }

            if ((int) $session->received_containers !== $washed + $damaged) {
                throw ValidationException::withMessages(['washed_containers' => 'Jumlah dicuci + rusak harus sama dengan jumlah yang diterima.']);
            }

            if ($washed !== $clean + $rejected) {
                throw ValidationException::withMessages(['clean_containers' => 'Jumlah bersih + ditolak harus sama dengan jumlah yang dicuci.']);
            }

            if ($clean <= 0) {
                throw ValidationException::withMessages(['clean_containers' => 'Minimal satu ompreng harus dinyatakan bersih.']);
            }

            $previousState = $session->state->value;
            $session->update([
                'washed_containers' => $washed,
                'clean_containers' => $clean,
                'damaged_containers' => $damaged,
                'rejected_containers' => $rejected,
                'missing_containers' => $missing,
                'completed_at' => $data['completed_at'] ?? now(),
                'state' => WashingSessionState::Completed,
                'updated_by' => $actor->getKey(),
                'notes' => $data['notes'] ?? $session->notes,
            ]);

            $this->writeHistory($session, $actor, 'completed', $previousState, $session->state->value, $data['notes'] ?? null);
            return $session->refresh();
        });
    }

    public function markReady(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Completed) {
                throw ValidationException::withMessages(['state' => 'Ompreng hanya dapat ditandai siap setelah pencucian selesai.']);
            }

            $previousState = $session->state->value;
            $session->update([
                'ready_at' => now(),
                'state' => WashingSessionState::Ready,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'ready', $previousState, $session->state->value, $notes);

            app(OperationalHandoverFlow::class)
                ->createCleaningSessionsAfterWashing($session->refresh(), $actor);

            return $session->refresh();
        });
    }

    public function submissionIssues(WashingSession $session): array
    {
        $session->load(['checklistItems', 'measurements', 'chemicalUsages', 'documentations', 'deviations']);
        $issues = [];

        if ($session->state !== WashingSessionState::Ready) {
            $issues[] = 'Sesi belum berada pada tahap Siap Digunakan.';
        }
        if ($session->expected_containers !== $session->received_containers + $session->missing_containers) {
            $issues[] = 'Rekonsiliasi jumlah diharapkan, diterima, dan hilang belum seimbang.';
        }
        if ($session->received_containers !== $session->washed_containers + $session->damaged_containers) {
            $issues[] = 'Jumlah diterima belum sama dengan jumlah dicuci + rusak.';
        }
        if ($session->washed_containers !== $session->clean_containers + $session->rejected_containers) {
            $issues[] = 'Jumlah dicuci belum sama dengan jumlah bersih + ditolak.';
        }
        if ($session->checklistItems->where('is_mandatory', true)->count() === 0) {
            $issues[] = 'Checklist wajib belum tersedia.';
        }
        if ($session->checklistItems->where('is_mandatory', true)->contains(fn ($item): bool => ! $item->is_passed)) {
            $issues[] = 'Masih ada checklist wajib yang belum lulus.';
        }
        if ($session->measurements->isEmpty()) {
            $issues[] = 'Minimal satu pengukuran suhu air wajib tersedia.';
        }
        if ($session->measurements->contains(fn ($item): bool => ! $item->is_within_limit && blank($item->corrective_action))) {
            $issues[] = 'Pengukuran di luar batas belum memiliki tindakan koreksi.';
        }
        if ($session->chemicalUsages->isEmpty()) {
            $issues[] = 'Penggunaan bahan pembersih atau sanitizer belum dicatat.';
        }
        if (! $session->documentations->contains('phase', 'before')) {
            $issues[] = 'Foto sebelum pencucian belum tersedia.';
        }
        if (! $session->documentations->contains('phase', 'after')) {
            $issues[] = 'Foto setelah pencucian belum tersedia.';
        }
        if ($session->deviations->contains(function ($item): bool {
            return in_array($item->severity, [WashingDeviationSeverity::High, WashingDeviationSeverity::Critical], true)
                && $item->status !== WashingDeviationStatus::Resolved;
        })) {
            $issues[] = 'Penyimpangan tinggi atau kritis belum diselesaikan.';
        }

        return $issues;
    }

    public function submit(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);
            $issues = $this->submissionIssues($session);

            if ($issues !== []) {
                throw ValidationException::withMessages(['submission' => implode(' ', $issues)]);
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

            $this->writeHistory($session, $actor, 'submitted', $session->state->value, $session->state->value, $notes, $previousStatus, $session->status->value);
            return $session->refresh();
        });
    }

    public function verify(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $session = $this->lockedSession($session);
            if (! app(OperationalReportApprovalService::class)->isReviewable($session->status)) {
                throw ValidationException::withMessages(['status' => 'Laporan tidak berada pada tahap persetujuan yang valid.']);
            }

            $previousStatus = $session->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($session->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);

            $updates = [
                'status' => $nextStatus,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ];
            if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                $updates += [
                    'division_approved_by' => $actor->getKey(),
                    'division_approved_at' => now(),
                ];
            } else {
                $updates += [
                    'verified_by' => $actor->getKey(),
                    'verified_at' => now(),
                ];
            }
            $session->update($updates);

            $this->writeHistory($session, $actor, $action, $session->state->value, $session->state->value, $notes, $previousStatus, $nextStatus->value);
            return $session->refresh();
        });
    }

    public function requestRevision(WashingSession $session, User $actor, string $notes): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $session = $this->lockedSession($session);
            app(OperationalReportApprovalService::class)->assertCanReviewStage($session->status, $actor);

            $previousStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'review_notes' => $notes,
                'division_approved_by' => null,
                'division_approved_at' => null,
                'verified_by' => null,
                'verified_at' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'revision_requested', $session->state->value, $session->state->value, $notes, $previousStatus, $session->status->value);
            return $session->refresh();
        });
    }

    private function ensureEditable(WashingSession $session): void
    {
        if (! $session->isReportEditable()) {
            throw ValidationException::withMessages(['status' => 'Laporan sudah dikunci dan tidak dapat diubah.']);
        }
    }

    private function lockedSession(WashingSession $session): WashingSession
    {
        return WashingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
    }

    private function writeHistory(
        WashingSession $session,
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
            'snapshot' => [
                'expected_containers' => $session->expected_containers,
                'received_containers' => $session->received_containers,
                'washed_containers' => $session->washed_containers,
                'clean_containers' => $session->clean_containers,
                'damaged_containers' => $session->damaged_containers,
                'rejected_containers' => $session->rejected_containers,
                'missing_containers' => $session->missing_containers,
            ],
        ]);
    }
}
