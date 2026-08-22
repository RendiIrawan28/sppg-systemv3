<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\WashingSessionState;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\User;
use App\Models\WashingSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
                throw ValidationException::withMessages([
                    'state' => 'Ompreng hanya dapat diterima dari status Menunggu Diterima.',
                ]);
            }

            $expected = max(0, (int) $session->expected_containers);
            $received = max(0, (int) ($data['received_containers'] ?? 0));
            $damaged = max(0, (int) ($data['damaged_containers'] ?? 0));
            $difference = $received - $expected;
            $missing = max(0, $expected - $received);
            $notes = trim((string) ($data['notes'] ?? $session->notes ?? ''));

            if ($expected <= 0) {
                throw ValidationException::withMessages([
                    'expected_containers' => 'Jumlah ompreng dari kegiatan pengambilan belum tersedia.',
                ]);
            }

            if ($received <= 0) {
                throw ValidationException::withMessages([
                    'received_containers' => 'Jumlah ompreng yang diterima harus lebih dari nol.',
                ]);
            }

            if ($damaged > $received) {
                throw ValidationException::withMessages([
                    'damaged_containers' => 'Jumlah ompreng rusak tidak boleh melebihi jumlah yang diterima.',
                ]);
            }

            if (($difference !== 0 || $damaged !== (int) $session->distribution_damaged_containers) && $notes === '') {
                throw ValidationException::withMessages([
                    'notes' => 'Catatan selisih wajib diisi karena hasil pemeriksaan berbeda dari jumlah yang dibawa kembali driver.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'received_containers' => $received,
                'damaged_containers' => $damaged,
                'missing_containers' => $missing,
                'receiving_difference' => $difference,
                'received_at' => now(),
                'state' => WashingSessionState::Received,
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'notes' => $notes !== '' ? $notes : $session->notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'received',
                $previousState,
                $session->state->value,
                $notes ?: null,
            );

            return $session->refresh();
        });
    }

    public function recordWaste(WashingSession $session, User $actor, array $data): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session)->load(['wasteRecords', 'wasteHandoverReport', 'sppgUnit']);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Received) {
                throw ValidationException::withMessages([
                    'state' => 'Limbah dicatat setelah ompreng diterima dan sebelum pencucian dimulai.',
                ]);
            }

            $hasFoodWaste = $this->booleanValue($data['has_food_waste'] ?? $session->has_food_waste);
            $noWasteConfirmed = $this->booleanValue($data['no_waste_confirmed'] ?? $session->no_waste_confirmed);

            if ($hasFoodWaste === null) {
                throw ValidationException::withMessages([
                    'has_food_waste' => 'Pilih apakah terdapat sisa limbah makanan.',
                ]);
            }

            $updates = [
                'has_food_waste' => $hasFoodWaste,
                'waste_recorded_at' => now(),
                'updated_by' => $actor->getKey(),
            ];

            if ($hasFoodWaste) {
                $errors = [];

                if ($session->wasteRecords->isEmpty()) {
                    $errors['wasteRecords'] = 'Tambahkan minimal satu jenis limbah makanan.';
                }

                foreach ($session->wasteRecords as $index => $record) {
                    $number = $index + 1;

                    if (blank($record->waste_type)) {
                        $errors["wasteRecords.{$index}.waste_type"] = "Jenis limbah baris {$number} wajib diisi.";
                    }
                    if ((float) $record->quantity <= 0) {
                        $errors["wasteRecords.{$index}.quantity"] = "Jumlah limbah baris {$number} harus lebih dari nol.";
                    }
                    if (blank($record->unit)) {
                        $errors["wasteRecords.{$index}.unit"] = "Satuan limbah baris {$number} wajib diisi.";
                    }
                    if (blank($record->disposal_method)) {
                        $errors["wasteRecords.{$index}.disposal_method"] = "Metode penanganan limbah baris {$number} wajib diisi.";
                    }
                    if (blank($record->handed_over_to)) {
                        $errors["wasteRecords.{$index}.handed_over_to"] = "Penerima limbah baris {$number} wajib diisi.";
                    }
                    if (blank($record->photo_path)) {
                        $errors["wasteRecords.{$index}.photo_path"] = "Foto limbah baris {$number} wajib tersedia.";
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $updates += [
                    'no_waste_confirmed' => false,
                    'waste_handed_over_at' => $session->wasteHandoverReport?->handed_over_at,
                    'waste_handover_notes' => trim((string) ($data['waste_handover_notes'] ?? $session->waste_handover_notes)) ?: null,
                ];
            } else {
                if (! $noWasteConfirmed) {
                    throw ValidationException::withMessages([
                        'no_waste_confirmed' => 'Centang pernyataan bahwa tidak terdapat limbah makanan.',
                    ]);
                }

                if ($session->wasteRecords->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'wasteRecords' => 'Hapus item limbah terlebih dahulu apabila memilih tidak terdapat limbah.',
                    ]);
                }

                $updates += [
                    'no_waste_confirmed' => true,
                    'waste_handed_over_at' => now(),
                    'waste_first_party_name' => null,
                    'waste_first_party_position' => null,
                    'waste_first_party_address' => null,
                    'waste_second_party_name' => null,
                    'waste_second_party_position' => null,
                    'waste_second_party_address' => null,
                    'waste_handover_notes' => trim((string) ($data['waste_handover_notes'] ?? '')) ?: null,
                ];
            }

            $session->update($updates);

            $this->writeHistory(
                $session,
                $actor,
                $hasFoodWaste ? 'waste_recorded' : 'no_waste_confirmed',
                $session->state->value,
                $session->state->value,
                $session->waste_handover_notes,
            );

            return $session->refresh();
        });
    }

    public function start(WashingSession $session, User $actor, array $data = []): WashingSession
    {
        try {
            app(MenuServiceCalendarService::class)->assertOperationalDate((int) $session->sppg_unit_id, $session->washing_date, 'Pencucian');
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['washing_date' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Received) {
                throw ValidationException::withMessages([
                    'state' => 'Pencucian hanya dapat dimulai setelah ompreng diterima.',
                ]);
            }

            if (! $session->wasteHandlingCompleted()) {
                throw ValidationException::withMessages([
                    'waste' => 'Selesaikan pencatatan atau konfirmasi limbah makanan terlebih dahulu.',
                ]);
            }

            if ((int) $session->received_containers <= 0) {
                throw ValidationException::withMessages([
                    'received_containers' => 'Tidak ada ompreng yang dapat dicuci.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'started_at' => now(),
                'state' => WashingSessionState::Washing,
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

    public function complete(WashingSession $session, User $actor, array $data): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $data): WashingSession {
            $session = $this->lockedSession($session)->load(['checklistItems', 'documentations']);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Washing) {
                throw ValidationException::withMessages([
                    'state' => 'Pencucian hanya dapat diselesaikan dari status Sedang Dicuci.',
                ]);
            }

            if (! $session->wasteHandlingCompleted()) {
                throw ValidationException::withMessages([
                    'waste' => 'Pencatatan limbah belum diselesaikan.',
                ]);
            }

            $clean = max(0, (int) ($data['clean_containers'] ?? $session->clean_containers));
            $damaged = max(0, (int) ($data['damaged_containers'] ?? $session->damaged_containers));
            $received = (int) $session->received_containers;

            if ($received !== $clean + $damaged) {
                throw ValidationException::withMessages([
                    'clean_containers' => 'Jumlah ompreng bersih + rusak/tidak layak harus sama dengan jumlah yang diterima.',
                ]);
            }

            $mandatory = $session->checklistItems->where('is_mandatory', true);
            if ($mandatory->isEmpty()) {
                throw ValidationException::withMessages([
                    'checklist' => 'Checklist pencucian belum tersedia.',
                ]);
            }
            if ($mandatory->contains(fn ($item): bool => ! $item->is_passed)) {
                throw ValidationException::withMessages([
                    'checklist' => 'Semua checklist wajib harus dicentang sebelum pencucian diselesaikan.',
                ]);
            }

            if (! $session->documentations->contains(fn ($item): bool => $item->phase === 'after' && filled($item->photo_path))) {
                throw ValidationException::withMessages([
                    'documentations' => 'Minimal satu foto hasil pencucian wajib tersedia.',
                ]);
            }

            $previousState = $session->state->value;
            $completedAt = now();
            $session->update([
                'washed_containers' => $received,
                'clean_containers' => $clean,
                'damaged_containers' => $damaged,
                'rejected_containers' => 0,
                'completed_at' => $completedAt,
                'ready_at' => $completedAt,
                'state' => WashingSessionState::Ready,
                'updated_by' => $actor->getKey(),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: $session->notes,
            ]);

            $this->writeHistory(
                $session,
                $actor,
                'completed_and_ready',
                $previousState,
                $session->state->value,
                $data['notes'] ?? null,
            );

            return $session->refresh();
        });
    }

    /** Kompatibilitas data lama yang masih berada pada status Completed. */
    public function markReady(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $session = $this->lockedSession($session);
            $this->ensureEditable($session);

            if ($session->state !== WashingSessionState::Completed) {
                throw ValidationException::withMessages([
                    'state' => 'Ompreng hanya dapat ditandai siap setelah pencucian selesai.',
                ]);
            }

            $previousState = $session->state->value;
            $session->update([
                'ready_at' => now(),
                'state' => WashingSessionState::Ready,
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

    /** @return array<int, string> */
    public function submissionIssues(WashingSession $session): array
    {
        $date = $session->washing_date?->toDateString() ?? now()->toDateString();

        $sessions = WashingSession::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->whereDate('washing_date', $date)
            ->with(['checklistItems', 'documentations', 'wasteRecords', 'containerCollectionRun', 'distributionRun'])
            ->orderBy('id')
            ->get();

        $issues = [];

        if ($sessions->isEmpty()) {
            return ['Belum ada sesi Pencucian pada tanggal tersebut.'];
        }

        $dayEnd = Carbon::parse($date)->endOfDay();
        $activeRunCount = ContainerCollectionRun::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->where('state', ContainerCollectionRun::ACTIVE)
            ->where('started_at', '<=', $dayEnd)
            ->count();

        if ($activeRunCount > 0) {
            $issues[] = 'Masih ada kegiatan pengambilan ompreng yang belum kembali ke SPPG.';
        }

        $runs = ContainerCollectionRun::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->where('state', ContainerCollectionRun::RETURNED)
            ->whereDate('returned_at', $date)
            ->get();

        $remainingTasks = ContainerCollectionTask::query()
            ->where('sppg_unit_id', $session->sppg_unit_id)
            ->whereDate('delivery_date', '<=', $date)
            ->where('remaining_containers', '>', 0)
            ->count();
        if ($remainingTasks > 0) {
            $issues[] = "Masih ada {$remainingTasks} tujuan dengan ompreng yang belum selesai diambil.";
        }

        $returnedRunIds = $runs
            ->where('state', ContainerCollectionRun::RETURNED)
            ->pluck('id');
        $sessionRunIds = $sessions->pluck('container_collection_run_id')->filter();
        $missingSessionCount = $returnedRunIds->diff($sessionRunIds)->count();
        if ($missingSessionCount > 0) {
            $issues[] = "Terdapat {$missingSessionCount} kegiatan pengambilan yang sudah kembali tetapi belum mempunyai sesi Pencucian.";
        }

        foreach ($sessions as $dailySession) {
            $label = $dailySession->containerCollectionRun?->run_number ?: $dailySession->distributionRun?->route_name ?: $dailySession->session_number;

            if ($dailySession->state !== WashingSessionState::Ready) {
                $issues[] = "{$label}: pencucian belum berstatus Siap Digunakan.";
            }
            if (! $dailySession->wasteHandlingCompleted()) {
                $issues[] = "{$label}: pencatatan limbah belum selesai.";
            }
            if ((int) $dailySession->received_containers !== (int) $dailySession->clean_containers + (int) $dailySession->damaged_containers) {
                $issues[] = "{$label}: jumlah diterima belum sama dengan jumlah bersih + rusak.";
            }
            if ($dailySession->checklistItems->where('is_mandatory', true)->contains(fn ($item): bool => ! $item->is_passed)) {
                $issues[] = "{$label}: masih ada checklist wajib yang belum dicentang.";
            }
            if (! $dailySession->documentations->contains(fn ($item): bool => $item->phase === 'after' && filled($item->photo_path))) {
                $issues[] = "{$label}: foto hasil pencucian belum tersedia.";
            }
            if ($dailySession->receiving_difference !== 0 && blank($dailySession->notes)) {
                $issues[] = "{$label}: selisih penerimaan belum memiliki catatan.";
            }
        }

        return array_values(array_unique($issues));
    }

    public function submit(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        $result = DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $reference = $this->lockedSession($session);
            $issues = $this->submissionIssues($reference);

            if ($issues !== []) {
                throw ValidationException::withMessages([
                    'submission' => implode(' ', $issues),
                ]);
            }

            $sessions = $this->lockDailySessions($reference);
            if ($sessions->contains(fn (WashingSession $item): bool => ! $item->isReportEditable())) {
                throw ValidationException::withMessages([
                    'status' => 'Seluruh sesi harian harus berstatus Draft atau Perlu Revisi.',
                ]);
            }

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

                $this->writeHistory(
                    $dailySession,
                    $actor,
                    'submitted_daily',
                    $dailySession->state->value,
                    $dailySession->state->value,
                    $notes,
                    $previousStatus,
                    OperationalReportStatus::Submitted->value,
                );
            }

            return WashingSession::query()->findOrFail($reference->getKey());
        });

        app(OperationalHandoverFlow::class)
            ->createCleaningSessionsAfterAllWashingForDate($result, $actor);

        return $result->refresh();
    }

    public function verify(WashingSession $session, User $actor, ?string $notes = null): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $reference = $this->lockedSession($session);
            app(OperationalReportApprovalService::class)->assertCanReviewStage($reference->status, $actor);

            $sessions = $this->lockDailySessions($reference);
            if ($sessions->contains(fn (WashingSession $item): bool => $item->status !== $reference->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Status laporan sesi pada tanggal yang sama tidak seragam.',
                ]);
            }

            $previousStatus = $reference->status->value;
            $nextStatus = app(OperationalReportApprovalService::class)
                ->nextApprovedStatus($reference->status, $actor);
            $action = app(OperationalReportApprovalService::class)
                ->reviewActionName($nextStatus);

            foreach ($sessions as $dailySession) {
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

                $dailySession->update($updates);
                $this->writeHistory(
                    $dailySession,
                    $actor,
                    $action.'_daily',
                    $dailySession->state->value,
                    $dailySession->state->value,
                    $notes,
                    $previousStatus,
                    $nextStatus->value,
                );
            }

            return WashingSession::query()->findOrFail($reference->getKey());
        });
    }

    public function requestRevision(WashingSession $session, User $actor, string $notes): WashingSession
    {
        return DB::transaction(function () use ($session, $actor, $notes): WashingSession {
            $reference = $this->lockedSession($session);
            app(OperationalReportApprovalService::class)->assertCanReviewStage($reference->status, $actor);

            $sessions = $this->lockDailySessions($reference);
            if ($sessions->contains(fn (WashingSession $item): bool => $item->status !== $reference->status)) {
                throw ValidationException::withMessages([
                    'status' => 'Status laporan sesi pada tanggal yang sama tidak seragam.',
                ]);
            }

            $previousStatus = $reference->status->value;

            foreach ($sessions as $dailySession) {
                $dailySession->update([
                    'status' => OperationalReportStatus::RevisionRequired,
                    'review_notes' => $notes,
                    'division_approved_by' => null,
                    'division_approved_at' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'updated_by' => $actor->getKey(),
                ]);

                $this->writeHistory(
                    $dailySession,
                    $actor,
                    'revision_requested_daily',
                    $dailySession->state->value,
                    $dailySession->state->value,
                    $notes,
                    $previousStatus,
                    OperationalReportStatus::RevisionRequired->value,
                );
            }

            return WashingSession::query()->findOrFail($reference->getKey());
        });
    }

    private function ensureEditable(WashingSession $session): void
    {
        if (! $session->isReportEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Laporan sudah dikunci dan tidak dapat diubah.',
            ]);
        }
    }

    private function lockedSession(WashingSession $session): WashingSession
    {
        return WashingSession::query()
            ->whereKey($session->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return Collection<int, WashingSession> */
    private function lockDailySessions(WashingSession $reference): Collection
    {
        return WashingSession::query()
            ->where('sppg_unit_id', $reference->sppg_unit_id)
            ->whereDate('washing_date', $reference->washing_date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function booleanValue(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
                'clean_containers' => $session->clean_containers,
                'damaged_containers' => $session->damaged_containers,
                'missing_containers' => $session->missing_containers,
                'receiving_difference' => $session->receiving_difference,
                'has_food_waste' => $session->has_food_waste,
                'waste_recorded_at' => $session->waste_recorded_at,
                'waste_handed_over_at' => $session->waste_handed_over_at,
            ],
        ]);
    }
}
