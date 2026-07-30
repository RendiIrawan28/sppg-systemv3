<?php

namespace App\Services;

use App\Enums\CleaningFindingSeverity;
use App\Enums\CleaningSessionState;
use App\Enums\OperationalReportStatus;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CleaningWorkflow
{
    public function start(CleaningSession $session, User $actor, array $data = []): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $data): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== CleaningSessionState::Planned) {
                throw ValidationException::withMessages(['state' => 'Pekerjaan hanya dapat dimulai dari status Menunggu.']);
            }

            if (! $session->cleaningArea?->is_active) {
                throw ValidationException::withMessages(['cleaning_area_id' => 'Area kebersihan tidak aktif.']);
            }

            $previousState = $session->state->value;
            $session->update([
                'started_at' => $data['started_at'] ?? now(),
                'state' => CleaningSessionState::InProgress,
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'started', $previousState, $session->state->value, $data['notes'] ?? null);

            return $session->refresh();
        });
    }

    public function complete(CleaningSession $session, User $actor, array $data): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $data): CleaningSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== CleaningSessionState::InProgress) {
                throw ValidationException::withMessages(['state' => 'Kebersihan hanya dapat diselesaikan dari status Sedang Dibersihkan.']);
            }

            $session->load(['checklistItems', 'documentations', 'findings', 'wasteHandoverReport']);
            $issues = $this->completionIssues($session, $data);
            if ($issues !== []) {
                throw ValidationException::withMessages(['completion' => implode(' ', $issues)]);
            }

            $previousState = $session->state->value;
            $completedAt = $data['completed_at'] ?? now();
            $session->update([
                'completed_at' => $completedAt,
                'ready_at' => $completedAt,
                'after_condition' => $data['after_condition'] ?? $session->after_condition,
                'notes' => $data['notes'] ?? $session->notes,
                'state' => CleaningSessionState::Ready,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($session, $actor, 'completed', $previousState, $session->state->value, $data['notes'] ?? null);

            return $session->refresh();
        });
    }

    public function markReady(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        if ($session->state === CleaningSessionState::Ready) {
            return $session;
        }

        return $this->complete($session, $actor, ['notes' => $notes]);
    }

    public function submissionIssues(CleaningSession $session, ?User $actor = null): array
    {
        $date = $session->scheduled_date?->toDateString();
        $actor ??= auth()->user() instanceof User ? auth()->user() : $session->petugas;
        if ($actor) {
            app(CleaningScheduleService::class)->ensureForDate((int) $session->sppg_unit_id, $date, $actor);
        }

        $requiredAreaIds = CleaningArea::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('auto_schedule')->orWhere('auto_schedule', true);
            })
            ->where(function ($query): void {
                $query->whereNull('frequency')->orWhere('frequency', 'daily');
            })
            ->pluck('id');

        $sessions = CleaningSession::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->whereDate('scheduled_date', $date)
            ->whereHas('cleaningArea', fn ($query) => $query->where('is_active', true))
            ->with(['cleaningArea', 'checklistItems', 'documentations', 'findings', 'wasteHandoverReport'])
            ->get();

        $issues = [];
        if ($sessions->isEmpty()) {
            return ['Belum ada sesi kebersihan untuk tanggal tersebut.'];
        }

        $missingAreaIds = $requiredAreaIds->diff($sessions->pluck('cleaning_area_id'));
        if ($missingAreaIds->isNotEmpty()) {
            $names = CleaningArea::query()->whereKey($missingAreaIds)->pluck('name')->implode(', ');
            $issues[] = 'Sesi area wajib belum tersedia: '.$names.'.';
        }

        foreach ($sessions as $dailySession) {
            if ($dailySession->state !== CleaningSessionState::Ready) {
                $issues[] = $dailySession->cleaningArea?->name.' belum selesai.';
                continue;
            }

            foreach ($this->completionIssues($dailySession, []) as $issue) {
                $issues[] = $dailySession->cleaningArea?->name.': '.$issue;
            }
        }

        return array_values(array_unique($issues));
    }

    public function submit(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);
            $issues = $this->submissionIssues($session, $actor);
            if ($issues !== []) {
                throw ValidationException::withMessages(['submission' => implode(' ', $issues)]);
            }

            $date = $session->scheduled_date?->toDateString();
            $sessions = CleaningSession::query()
                ->where('sppg_unit_id', $session->sppg_unit_id)
                ->whereDate('scheduled_date', $date)
                ->whereHas('cleaningArea', fn ($query) => $query->where('is_active', true))
                ->whereIn('status', [OperationalReportStatus::Draft->value, OperationalReportStatus::RevisionRequired->value])
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $dailySession) {
                $previousStatus = $dailySession->status->value;
                $dailySession->update([
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
                $this->writeHistory($dailySession, $actor, 'submitted_daily', $dailySession->state->value, $dailySession->state->value, $notes, $previousStatus, $dailySession->status->value);
            }

            return $session->refresh();
        });
    }

    public function verify(CleaningSession $session, User $actor, ?string $notes = null): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);
            app(OperationalReportApprovalService::class)->assertCanReviewStage($session->status, $actor);
            $nextStatus = app(OperationalReportApprovalService::class)->nextApprovedStatus($session->status, $actor);
            $action = app(OperationalReportApprovalService::class)->reviewActionName($nextStatus);
            $date = $session->scheduled_date?->toDateString();

            $sessions = CleaningSession::query()
                ->where('sppg_unit_id', $session->sppg_unit_id)
                ->whereDate('scheduled_date', $date)
                ->whereHas('cleaningArea', fn ($query) => $query->where('is_active', true))
                ->where('status', $session->status->value)
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $dailySession) {
                $previousStatus = $dailySession->status->value;
                $updates = ['status' => $nextStatus, 'review_notes' => $notes, 'updated_by' => $actor->getKey()];
                if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                    $updates += ['division_approved_by' => $actor->getKey(), 'division_approved_at' => now()];
                } else {
                    $updates += ['verified_by' => $actor->getKey(), 'verified_at' => now()];
                }
                $dailySession->update($updates);
                $this->writeHistory($dailySession, $actor, $action.'_daily', $dailySession->state->value, $dailySession->state->value, $notes, $previousStatus, $nextStatus->value);
            }

            return $session->refresh();
        });
    }

    public function requestRevision(CleaningSession $session, User $actor, string $notes): CleaningSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): CleaningSession {
            $session = $this->lockedSession($session);
            app(OperationalReportApprovalService::class)->assertCanReviewStage($session->status, $actor);
            $date = $session->scheduled_date?->toDateString();

            $sessions = CleaningSession::query()
                ->where('sppg_unit_id', $session->sppg_unit_id)
                ->whereDate('scheduled_date', $date)
                ->whereHas('cleaningArea', fn ($query) => $query->where('is_active', true))
                ->where('status', $session->status->value)
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $dailySession) {
                $previousStatus = $dailySession->status->value;
                $dailySession->update([
                    'status' => OperationalReportStatus::RevisionRequired,
                    'review_notes' => $notes,
                    'division_approved_by' => null,
                    'division_approved_at' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'updated_by' => $actor->getKey(),
                ]);
                $this->writeHistory($dailySession, $actor, 'revision_requested_daily', $dailySession->state->value, $dailySession->state->value, $notes, $previousStatus, $dailySession->status->value);
            }

            return $session->refresh();
        });
    }

    private function completionIssues(CleaningSession $session, array $data): array
    {
        $session->loadMissing(['checklistItems', 'documentations', 'findings', 'wasteHandoverReport']);
        $issues = [];
        $mandatory = $session->checklistItems->where('is_mandatory', true);

        if ($mandatory->isEmpty()) {
            $issues[] = 'Checklist wajib belum tersedia.';
        }
        if ($mandatory->contains(fn ($item): bool => ! in_array($item->result, ['pass', 'fail'], true))) {
            $issues[] = 'Semua item checklist wajib harus ditandai terpenuhi atau tidak terpenuhi.';
        }
        if ($mandatory->contains(fn ($item): bool => $item->result === 'fail' && blank($item->notes))) {
            $issues[] = 'Item yang tidak terpenuhi wajib memiliki evaluasi.';
        }
        if (! $session->documentations->contains(fn ($documentation): bool => $documentation->phase === 'after' && filled($documentation->photo_path))) {
            $issues[] = 'Minimal satu foto hasil kebersihan wajib tersedia.';
        }
        if ($session->waste_presence === 'yes' && ! $session->wasteHandoverReport?->isOperationallyUsable()) {
            $issues[] = 'Berita acara limbah wajib dibuat dan diajukan karena terdapat limbah.';
        }
        if (! in_array($session->waste_presence, ['yes', 'none'], true)) {
            $issues[] = 'Pilih apakah terdapat limbah pada pekerjaan ini.';
        }
        if ($session->findings->contains(function ($finding): bool {
            return $finding->severity === CleaningFindingSeverity::Critical && blank($finding->corrective_action);
        })) {
            $issues[] = 'Temuan kritis wajib memiliki tindakan sementara.';
        }

        return $issues;
    }

    private function ensureEditable(CleaningSession $session): void
    {
        if (! $session->isReportEditable()) {
            throw ValidationException::withMessages(['status' => 'Laporan sudah dikunci dan tidak dapat diubah.']);
        }
    }

    private function lockedSession(CleaningSession $session): CleaningSession
    {
        return CleaningSession::query()->with('cleaningArea')->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
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
