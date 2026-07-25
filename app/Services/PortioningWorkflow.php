<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningDeviationSeverity;
use App\Enums\PortioningDeviationStatus;
use App\Enums\PortioningSessionState;
use App\Enums\PortionSize;
use App\Models\DistributionRun;
use App\Models\PortioningHandover;
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

        if ((float) $session->received_output_quantity <= 0
            && $session->processingBatch?->state?->value === 'completed') {
            app(PortioningInputService::class)->syncProcessingCompletion(
                $session->processingBatch,
                $actor,
            );
        }

        return DB::transaction(function () use ($session, $actor): PortioningSession {
            $session = $this->lockedSession($session);

            if (! $session->isReportEditable() || $session->state !== PortioningSessionState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Sesi pemorsian tidak dapat dimulai pada kondisi saat ini.',
                ]);
            }
            if ((float) $session->received_output_quantity <= 0 || blank($session->received_output_unit)) {
                throw ValidationException::withMessages(['input' => 'Sesi belum menerima hasil produksi dari Divisi Pengolahan.']);
            }
            if ($session->routeAllocations->isEmpty()) {
                throw ValidationException::withMessages(['routeAllocations' => 'Pembagian rute belum tersedia.']);
            }
            app(PortioningInputService::class)->ensureChecklist($session);

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
            $pendingWithdrawalIds = $session->supplies
                ->where('source_type', 'warehouse_withdrawal')
                ->pluck('source_id')
                ->filter();
            if ($pendingWithdrawalIds->isNotEmpty()
                && WarehouseWithdrawal::query()->whereIn('id', $pendingWithdrawalIds)->where('status', '!=', WarehouseWithdrawal::VERIFIED)->exists()) {
                throw ValidationException::withMessages(['supplies' => 'Pemorsian dapat berjalan, tetapi belum dapat diselesaikan sampai pengambilan diverifikasi Gudang.']);
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

            if (blank($data['received_by_name'] ?? null) || blank($data['photo_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'received_by_name' => 'Nama penerima dan foto serah-terima Distribusi wajib diisi.',
                ]);
            }
            $handoverTemperature = $session->temperatureLogs->firstWhere('checkpoint', 'before_handover');
            if (! $handoverTemperature || ($handoverTemperature->minimum_temperature === null && $handoverTemperature->maximum_temperature === null)) {
                throw ValidationException::withMessages(['temperatureLogs' => 'Suhu sebelum Distribusi beserta batas amannya wajib dicatat.']);
            }
            if (! $handoverTemperature->is_within_limit && blank($handoverTemperature->corrective_action)) {
                throw ValidationException::withMessages(['temperatureLogs' => 'Suhu sebelum Distribusi di luar batas wajib memiliki tindakan koreksi.']);
            }

            $handover = $session->handover()->updateOrCreate(
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

            $this->syncDistributionRun($session, $handover, $handoverTemperature->temperature_celsius, $actor);

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

    private function syncDistributionRun(
        PortioningSession $session,
        PortioningHandover $handover,
        float $temperature,
        User $actor,
    ): void {
        $run = DistributionRun::query()
            ->where('portioning_session_id', $session->getKey())
            ->lockForUpdate()
            ->first();

        if (! $run) {
            return;
        }

        $allocations = $session->routeAllocations()->get();
        foreach ($allocations as $allocation) {
            $stopQuery = $run->stops();
            if ($allocation->field_distribution_plan_destination_id) {
                $stopQuery->where('field_distribution_plan_destination_id', $allocation->field_distribution_plan_destination_id);
            } else {
                $stopQuery
                    ->where('route_name', $allocation->route_name)
                    ->where('destination_name', $allocation->destination_name);
            }

            $stop = $stopQuery->first();
            if (! $stop) {
                continue;
            }

            $small = (int) $allocation->actual_small_portions;
            $large = (int) $allocation->actual_large_portions;
            $stop->update([
                'small_portions' => $small,
                'large_portions' => $large,
                'containers_sent' => $small + $large,
            ]);
        }

        $run->update([
            'portioning_handover_id' => $handover->getKey(),
            'departure_temperature_celsius' => $temperature,
            'updated_by' => $actor->getKey(),
        ]);
        $run->recalculateTotals();
    }

    public function submit(PortioningSession $session, User $actor, ?string $notes = null): PortioningSession
    {
        abort_unless($actor->can('portioning.submit'), 403);

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

            app(OperationalReportApprovalService::class)->assertCanReviewStage($session->status, $actor);
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
            ->with(['routeAllocations', 'weightSamples', 'checklistItems', 'temperatureLogs', 'documentations', 'deviations', 'handover'])
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

        $requiredChecklistCategories = ['hygiene', 'sanitation', 'cross_contamination', 'portion_standard', 'special_diet', 'packaging', 'time_temperature', 'reconciliation'];
        $completedChecklistCategories = $session->checklistItems->where('result', 'pass')->pluck('category')->all();
        if (array_diff($requiredChecklistCategories, $completedChecklistCategories) !== []) {
            $issues[] = 'Seluruh checklist wajib Pemorsian harus dinyatakan lulus.';
        }

        foreach (['before', 'after'] as $phase) {
            if ($session->documentations->where('phase', $phase)->isEmpty()) {
                $issues[] = 'Foto sebelum dan sesudah Pemorsian wajib tersedia.';
                break;
            }
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

        $requiredSizes = collect([
            (int) $session->target_small_portions > 0 ? PortionSize::Small : null,
            (int) $session->target_large_portions > 0 ? PortionSize::Large : null,
        ])->filter();
        foreach ($requiredSizes as $size) {
            if ($session->weightSamples->where('portion_size', $size)->isEmpty()) {
                $errors['weightSamples'] = 'Setiap kategori porsi aktif wajib memiliki sampel berat.';
            }
        }

        $requiredChecklistCategories = ['hygiene', 'sanitation', 'cross_contamination', 'portion_standard', 'special_diet', 'packaging', 'time_temperature', 'reconciliation'];
        $completedChecklistCategories = $session->checklistItems->where('result', 'pass')->pluck('category')->all();
        if (array_diff($requiredChecklistCategories, $completedChecklistCategories) !== []) {
            $errors['checklistItems'] = 'Seluruh checklist wajib Pemorsian harus dinyatakan lulus.';
        }

        $processTemperature = $session->temperatureLogs->firstWhere('checkpoint', 'during_portioning');
        if (! $processTemperature || ($processTemperature->minimum_temperature === null && $processTemperature->maximum_temperature === null)) {
            $errors['temperatureLogs'] = 'Suhu saat pemorsian beserta batas amannya wajib dicatat.';
        } elseif (! $processTemperature->is_within_limit && blank($processTemperature->corrective_action)) {
            $errors['temperatureLogs'] = 'Suhu pemorsian di luar batas wajib memiliki tindakan koreksi.';
        }

        foreach (['before', 'after'] as $phase) {
            if ($session->documentations->where('phase', $phase)->isEmpty()) {
                $errors['documentations'] = 'Foto sebelum dan sesudah Pemorsian wajib tersedia.';
            }
        }

        $hasQuantityVariance = $session->actual_total !== $session->target_total
            || (strtolower((string) $session->received_output_unit) === 'porsi'
                && $session->actual_total !== (int) $session->received_output_quantity);
        if ($hasQuantityVariance && blank($session->input_variance_notes)) {
            $errors['input_variance_notes'] = 'Perbedaan hasil Pengolahan, target, dan realisasi porsi wajib dijelaskan.';
        }

        $blockingDeviation = $session->deviations->first(function ($deviation): bool {
            return in_array($deviation->severity, [PortioningDeviationSeverity::High, PortioningDeviationSeverity::Critical], true)
                && $deviation->status !== PortioningDeviationStatus::Resolved;
        });
        if ($blockingDeviation) {
            $errors['deviations'] = 'Penyimpangan tinggi atau kritis harus diselesaikan sebelum Pemorsian ditutup.';
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
                'supplies',
                'checklistItems',
                'temperatureLogs',
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
