<?php

namespace App\Services;

use App\Enums\CleaningFindingSeverity;
use App\Enums\CleaningFindingStatus;
use App\Enums\CleaningSessionState;
use App\Enums\OperationalReportStatus;
use App\Models\CleaningSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CleaningWorkflow
{
    public function start(CleaningSession $session, User $actor, array $data): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $data): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== CleaningSessionState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Pembersihan hanya dapat dimulai dari tahap Direncanakan.',
                ]);
            }

            if (! $session->cleaningArea?->is_active) {
                throw ValidationException::withMessages([
                    'cleaning_area_id' => 'Area kebersihan tidak aktif.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'started_at' => $data['started_at'] ?? now(),
                'state' => CleaningSessionState::InProgress,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'started',
                $previousState,
                $session->state->value,
                $data['notes'] ?? null,
            );

            return $session->refresh();
        });
    }

    public function complete(CleaningSession $session, User $actor, array $data): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $data): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== CleaningSessionState::InProgress) {
                throw ValidationException::withMessages([
                    'state' => 'Pembersihan hanya dapat diselesaikan dari tahap Sedang Dibersihkan.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'completed_at' => $data['completed_at'] ?? now(),
                'after_condition' => $data['after_condition'] ?? $session->after_condition,
                'notes' => $data['notes'] ?? $session->notes,
                'state' => CleaningSessionState::Completed,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'completed',
                $previousState,
                $session->state->value,
                $data['notes'] ?? null,
            );

            return $session->refresh();
        });
    }

    public function markReady(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== CleaningSessionState::Completed) {
                throw ValidationException::withMessages([
                    'state' => 'Area hanya dapat ditandai siap setelah pembersihan selesai.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'ready_at' => now(),
                'state' => CleaningSessionState::Ready,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'ready',
                $previousState,
                $session->state->value,
                $notes,
            );

            return $session->refresh();
        });
    }

    public function submissionIssues(CleaningSession $session): array
    {
        $session->load([
            'cleaningArea',
            'checklistItems',
            'chemicalUsages',
            'documentations',
            'findings',
        ]);

        $issues = [];

        if ($session->state !== CleaningSessionState::Ready) {
            $issues[] = 'Sesi belum berada pada tahap Siap Diverifikasi.';
        }

        if (! $session->cleaningArea?->is_active) {
            $issues[] = 'Area kebersihan tidak aktif.';
        }

        if (blank($session->after_condition)) {
            $issues[] = 'Kondisi area setelah pembersihan belum diisi.';
        }

        $mandatoryItems = $session->checklistItems->where('is_mandatory', true);
        if ($mandatoryItems->isEmpty()) {
            $issues[] = 'Checklist wajib belum tersedia.';
        }

        if ($mandatoryItems->contains(fn ($item): bool => ! in_array($item->result, ['pass', 'not_applicable'], true))) {
            $issues[] = 'Masih ada checklist wajib yang belum lulus.';
        }

        if ($mandatoryItems->contains(fn ($item): bool => $item->result === 'not_applicable' && blank($item->notes))) {
            $issues[] = 'Checklist Tidak Berlaku wajib memiliki alasan.';
        }

        if ($session->chemicalUsages->isEmpty()) {
            $issues[] = 'Penggunaan bahan pembersih belum dicatat.';
        }

        if (! $session->documentations->contains('phase', 'before')) {
            $issues[] = 'Foto sebelum pembersihan belum tersedia.';
        }

        if (! $session->documentations->contains('phase', 'after')) {
            $issues[] = 'Foto setelah pembersihan belum tersedia.';
        }

        if ($session->findings->contains(function ($finding): bool {
            return in_array(
                $finding->severity,
                [CleaningFindingSeverity::High, CleaningFindingSeverity::Critical],
                true,
            ) && $finding->status !== CleaningFindingStatus::Resolved;
        })) {
            $issues[] = 'Temuan tingkat tinggi atau kritis belum diselesaikan.';
        }

        return $issues;
    }

    public function submit(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);
            $issues = $this->submissionIssues($session);

            if ($issues !== []) {
                throw ValidationException::withMessages([
                    'submission' => implode(' ', $issues),
                ]);
            }

            $previousStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
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

    public function verify(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);

            if (! app(OperationalReportApprovalService::class)->isReviewable($session->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak berada pada tahap persetujuan yang valid.',
                ]);
            }

            $previousStatus = $session->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($session->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);

            $session->update([
                'status' => $nextStatus,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

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

    public function requestRevision(CleaningSession $session, User $actor, string $notes): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);

            if (! app(OperationalReportApprovalService::class)->isReviewable($session->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya laporan yang diajukan yang dapat dikembalikan.',
                ]);
            }

            $previousStatus = $session->status->value;
            $session->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'review_notes' => $notes,
                'verified_by' => null,
                'verified_at' => null,
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

    private function ensureEditable(CleaningSession $session): void
    {
        if (! $session->isReportEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Laporan sudah dikunci dan tidak dapat diubah.',
            ]);
        }
    }

    private function lockedSession(CleaningSession $session): CleaningSession
    {
        return CleaningSession::query()
            ->with('cleaningArea')
            ->whereKey($session->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function writeHistory(
        CleaningSession $session,
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
                'cleaning_area_id' => $session->cleaning_area_id,
                'scheduled_date' => $session->scheduled_date?->toDateString(),
                'shift' => $session->shift,
                'completion_percentage' => $session->completion_percentage,
            ],
        ]);
    }
}
