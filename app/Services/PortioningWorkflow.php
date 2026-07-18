<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningDeviationSeverity;
use App\Enums\PortioningDeviationStatus;
use App\Enums\PortioningSessionState;
use App\Models\PortioningSession;
use App\Models\User;
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

            return $session->refresh();
        });
    }

    public function handover(PortioningSession $session, User $actor, array $data): PortioningSession
    {
        abort_unless($actor->can('portioning.update'), 403);

        return DB::transaction(function () use ($session, $actor, $data): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->isReportEditable() || $session->state !== PortioningSessionState::Completed) {
                throw ValidationException::withMessages([
                    'state' => 'Pemorsian harus diselesaikan sebelum diserahkan ke Distribusi.',
                ]);
            }

            $session->recalculateTotals();
            $session = $this->lockedSession($session);

            $small = (int) ($data['small_portions'] ?? 0);
            $large = (int) ($data['large_portions'] ?? 0);

            if ($small !== (int) $session->actual_small_portions || $large !== (int) $session->actual_large_portions) {
                throw ValidationException::withMessages([
                    'small_portions' => 'Jumlah serah-terima harus sama dengan realisasi pemorsian per rute.',
                ]);
            }

            if (blank($data['received_by_name'] ?? null)) {
                throw ValidationException::withMessages([
                    'received_by_name' => 'Nama penerima Distribusi wajib diisi.',
                ]);
            }

            $session->handover()->updateOrCreate(
                ['portioning_session_id' => $session->getKey()],
                [
                    'handed_over_at' => $data['handed_over_at'] ?? now(),
                    'small_portions' => $small,
                    'large_portions' => $large,
                    'received_by_user_id' => $data['received_by_user_id'] ?? null,
                    'received_by_name' => $data['received_by_name'],
                    'photo_path' => $data['photo_path'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                ],
            );

            $previousState = $session->state->value;
            $session->update([
                'state' => PortioningSessionState::HandedOver,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'handed_over',
                $previousState,
                $session->state->value,
                $data['notes'] ?? null,
            );

            return $session->refresh();
        });
    }

    public function submit(PortioningSession $session, User $actor, ?string $notes = null): PortioningSession
    {
        abort_unless($actor->can('portioning.update'), 403);

        return DB::transaction(function () use ($session, $actor, $notes): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->canBeSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Sesi belum dapat diajukan. Selesaikan pemorsian dan serah-terima terlebih dahulu.',
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

    public function requestRevision(PortioningSession $session, User $actor, string $notes): PortioningSession
    {
        abort_unless($actor->can('portioning.approve'), 403);

        return DB::transaction(function () use ($session, $actor, $notes): PortioningSession {
            $session = $this->lockedSession($session);

            if (! app(OperationalReportApprovalService::class)->isReviewable($session->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan tidak sedang menunggu verifikasi.',
                ]);
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
            ->with(['routeAllocations', 'weightSamples', 'documentations', 'deviations', 'handover'])
            ->findOrFail($session->getKey());

        $issues = [];

        if ($session->state !== PortioningSessionState::HandedOver) {
            $issues[] = 'Serah-terima ke Distribusi belum dilakukan.';
        }

        if ($session->routeAllocations->isEmpty()) {
            $issues[] = 'Minimal satu rute pemorsian wajib tersedia.';
        }

        if ($session->weightSamples->isEmpty()) {
            $issues[] = 'Minimal satu sampel berat porsi wajib tersedia.';
        }

        if ($session->weightSamples->contains(fn ($sample): bool => ! $sample->is_within_tolerance && blank($sample->corrective_action))) {
            $issues[] = 'Sampel berat di luar toleransi wajib memiliki tindakan koreksi.';
        }

        if ($session->documentations->isEmpty()) {
            $issues[] = 'Minimal satu foto dokumentasi wajib tersedia.';
        }

        if (! $session->handover) {
            $issues[] = 'Data serah-terima ke Distribusi belum tersedia.';
        }

        $blockingDeviation = $session->deviations->first(function ($deviation): bool {
            return in_array($deviation->severity, [
                PortioningDeviationSeverity::High,
                PortioningDeviationSeverity::Critical,
            ], true) && $deviation->status !== PortioningDeviationStatus::Resolved;
        });

        if ($blockingDeviation) {
            $issues[] = 'Penyimpangan tinggi atau kritis harus diselesaikan.';
        }

        return $issues;
    }

    private function validateBeforeComplete(PortioningSession $session): void
    {
        $errors = [];

        if ($session->routeAllocations->isEmpty()) {
            $errors['routeAllocations'] = 'Minimal satu rute pemorsian harus dicatat.';
        }

        if ($session->actual_total <= 0) {
            $errors['routeAllocations'] = 'Jumlah realisasi porsi harus lebih dari nol.';
        }

        if ($session->weightSamples->isEmpty()) {
            $errors['weightSamples'] = 'Minimal satu sampel berat porsi wajib dicatat.';
        }

        if ($session->weightSamples->contains(fn ($sample): bool => ! $sample->is_within_tolerance && blank($sample->corrective_action))) {
            $errors['weightSamples'] = 'Sampel berat di luar toleransi wajib memiliki tindakan koreksi.';
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
                'weightSamples',
                'leftoverRecords',
                'documentations',
                'deviations',
                'handover',
            ])
            ->lockForUpdate()
            ->findOrFail($session->getKey());
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
                'weightSamples',
                'leftoverRecords',
                'documentations',
                'deviations',
                'handover',
            ])->toArray(),
        ]);
    }
}
