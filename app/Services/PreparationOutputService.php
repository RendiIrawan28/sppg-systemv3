<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\PortioningSession;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\ProcessingBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationOutputService
{
    /** @param array<int, string> $targets */
    public function syncSessionOutputs(PreparationSession $session, User $actor, array $targets): void
    {
        abort_unless($actor->can('preparation.update'), 403);
        DB::transaction(function () use ($session, $actor, $targets): void {
            $session = PreparationSession::query()->with(['items.resultDocumentation', 'outputs.withdrawals'])
                ->lockForUpdate()->findOrFail($session->getKey());
            foreach ($session->items as $item) {
                $target = $targets[$item->getKey()] ?? 'processing';
                if (! in_array($target, ['processing', 'portioning'], true)) {
                    throw ValidationException::withMessages(['target_division' => 'Tujuan hasil Persiapan tidak valid.']);
                }
                $quantity = (float) $item->processed_quantity;
                $output = $session->outputs->firstWhere('preparation_session_item_id', $item->getKey());
                $reserved = $output?->withdrawals->whereIn('status', [
                    PreparationOutputWithdrawal::WAITING,
                    PreparationOutputWithdrawal::VERIFIED,
                ])->sum(fn ($row): float => (float) ($row->verified_quantity ?: $row->requested_quantity)) ?? 0;
                if ($quantity <= 0) {
                    if ($output && $reserved <= 0) $output->delete();
                    continue;
                }
                $available = max(0, $quantity - $reserved);
                PreparationOutput::updateOrCreate(
                    ['preparation_session_item_id' => $item->getKey()],
                    [
                        'sppg_unit_id' => $session->sppg_unit_id,
                        'preparation_session_id' => $session->getKey(),
                        'ingredient_id' => $item->ingredient_id,
                        'output_name' => $item->ingredient_name_snapshot.' siap',
                        'source_ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                        'quantity' => $quantity,
                        'available_quantity' => $available,
                        'unit_snapshot' => $item->unit_snapshot,
                        'target_division' => $target,
                        'stored_at' => now(),
                        'state' => $available <= 0 ? PreparationOutput::DEPLETED : ($available < $quantity ? PreparationOutput::PARTIALLY_TAKEN : PreparationOutput::AVAILABLE),
                        'photo_path' => $item->resultDocumentation?->photo_path,
                        'notes' => $item->notes,
                        'created_by' => $output?->created_by ?: $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                    ],
                );
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function store(
        PreparationSession $session,
        PreparationSessionItem $item,
        User $actor,
        array $data,
    ): PreparationOutput {
        abort_unless($actor->can('preparation.update'), 403);

        return DB::transaction(function () use ($session, $item, $actor, $data): PreparationOutput {
            $session = PreparationSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $item = $session->items()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($session->state, ['in_progress', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'output' => 'Hasil Persiapan hanya dapat disimpan saat sesi sedang dikerjakan atau sudah selesai.',
                ]);
            }

            $quantity = (float) ($data['quantity'] ?? 0);
            $unit = trim((string) ($data['unit_snapshot'] ?? ''));
            $name = trim((string) ($data['output_name'] ?? ''));
            $target = (string) ($data['target_division'] ?? '');

            if ($name === '' || $quantity <= 0 || $unit === '') {
                throw ValidationException::withMessages([
                    'output' => 'Nama hasil, jumlah, dan satuan wajib diisi.',
                ]);
            }

            if (! in_array($target, ['processing', 'portioning', 'both'], true)) {
                throw ValidationException::withMessages([
                    'target_division' => 'Tujuan hasil Persiapan tidak valid.',
                ]);
            }

            return PreparationOutput::create([
                'sppg_unit_id' => $session->sppg_unit_id,
                'preparation_session_id' => $session->getKey(),
                'preparation_session_item_id' => $item->getKey(),
                'ingredient_id' => $item->ingredient_id,
                'output_name' => $name,
                'source_ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                'quantity' => $quantity,
                'available_quantity' => $quantity,
                'unit_snapshot' => $unit,
                'target_division' => $target,
                'storage_location' => trim((string) ($data['storage_location'] ?? '')) ?: null,
                'stored_at' => $data['stored_at'] ?? now(),
                'expires_at' => $data['expires_at'] ?? null,
                'state' => PreparationOutput::AVAILABLE,
                'photo_path' => $data['photo_path'] ?? null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });
    }

    public function changeTargetDivision(
        PreparationOutput $output,
        User $actor,
        string $targetDivision,
    ): PreparationOutput {
        abort_unless(
            $actor->is_super_admin
            || $actor->hasRole(UserRole::KepalaDivisiPersiapan->value),
            403,
        );

        return DB::transaction(function () use ($output, $actor, $targetDivision): PreparationOutput {
            $output = PreparationOutput::query()
                ->whereKey($output->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $targetDivision = trim($targetDivision);

            if (! in_array($targetDivision, ['processing', 'portioning', 'both'], true)) {
                throw ValidationException::withMessages([
                    'target_division' => 'Tujuan penggunaan hasil Persiapan tidak valid.',
                ]);
            }

            if (
                (float) $output->available_quantity <= 0
                || ! in_array($output->state, [
                    PreparationOutput::AVAILABLE,
                    PreparationOutput::PARTIALLY_TAKEN,
                ], true)
            ) {
                throw ValidationException::withMessages([
                    'target_division' => 'Tujuan hanya dapat diubah untuk barang yang masih tersedia.',
                ]);
            }

            $output->update([
                'target_division' => $targetDivision,
                'updated_by' => $actor->getKey(),
            ]);

            return $output->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function requestWithdrawal(PreparationOutput $output, User $actor, array $data): PreparationOutputWithdrawal
    {
        return DB::transaction(function () use ($output, $actor, $data): PreparationOutputWithdrawal {
            $output = PreparationOutput::query()->lockForUpdate()->findOrFail($output->getKey());
            $division = (string) ($data['destination_division'] ?? '');

            $permission = match ($division) {
                'processing' => 'processing.update',
                'portioning' => 'portioning.update',
                default => null,
            };

            abort_unless($permission && ($actor->can($permission) || $actor->can('preparation.update')), 403);

            if ($output->expires_at && $output->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'output' => 'Hasil Persiapan sudah melewati batas penggunaan dan tidak dapat diambil.',
                ]);
            }

            if (! $output->isAvailableFor($division)) {
                throw ValidationException::withMessages([
                    'output' => 'Hasil Persiapan tidak tersedia untuk divisi ini.',
                ]);
            }

            $quantity = (float) ($data['requested_quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > (float) $output->available_quantity) {
                throw ValidationException::withMessages([
                    'requested_quantity' => 'Jumlah yang diambil harus lebih dari nol dan tidak boleh melebihi stok tersedia.',
                ]);
            }

            $deferTarget = (bool) ($data['defer_target'] ?? false);
            if (! $deferTarget && $division === 'processing' && empty($data['processing_batch_id'])) {
                throw ValidationException::withMessages([
                    'processing_batch_id' => 'Batch Pengolahan tujuan wajib dipilih.',
                ]);
            }

            if (! $deferTarget && $division === 'portioning' && empty($data['portioning_session_id'])) {
                throw ValidationException::withMessages([
                    'portioning_session_id' => 'Sesi Pemorsian tujuan wajib dipilih.',
                ]);
            }

            if (! $deferTarget && $division === 'processing') {
                ProcessingBatch::query()
                    ->where('sppg_unit_id', $output->sppg_unit_id)
                    ->where('state', 'in_progress')
                    ->findOrFail((int) $data['processing_batch_id']);
            } elseif (! $deferTarget) {
                PortioningSession::query()
                    ->where('sppg_unit_id', $output->sppg_unit_id)
                    ->where('state', 'in_progress')
                    ->findOrFail((int) $data['portioning_session_id']);
            }

            $withdrawal = $output->withdrawals()->create([
                'destination_division' => $division,
                'processing_batch_id' => $division === 'processing' ? ($data['processing_batch_id'] ?? null) : null,
                'portioning_session_id' => $division === 'portioning' ? ($data['portioning_session_id'] ?? null) : null,
                'requested_quantity' => $quantity,
                'verified_quantity' => null,
                'unit_snapshot' => $output->unit_snapshot,
                'status' => PreparationOutputWithdrawal::WAITING,
                'taken_by' => $actor->getKey(),
                'taken_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'review_notes' => null,
            ]);

            $this->reserveQuantity($output, $quantity, $actor);

            return $withdrawal->refresh();
        });
    }

    public function receiveUnassignedWithdrawal(
        PreparationOutputWithdrawal $withdrawal,
        ProcessingBatch|PortioningSession $target,
        User $actor,
    ): PreparationOutputWithdrawal {
        $division = $target instanceof ProcessingBatch ? 'processing' : 'portioning';
        abort_unless($actor->can($division.'.update'), 403);

        return DB::transaction(function () use ($withdrawal, $target, $actor, $division): PreparationOutputWithdrawal {
            $withdrawal = PreparationOutputWithdrawal::query()
                ->with('output')
                ->lockForUpdate()
                ->findOrFail($withdrawal->getKey());

            if ($withdrawal->status !== PreparationOutputWithdrawal::WAITING
                || $withdrawal->destination_division !== $division) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Hasil Persiapan ini tidak tersedia untuk diterima.',
                ]);
            }
            if ((int) $withdrawal->output->sppg_unit_id !== (int) $target->sppg_unit_id) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Hasil Persiapan berasal dari unit SPPG yang berbeda.',
                ]);
            }
            if ($target instanceof ProcessingBatch && $target->state->value !== 'in_progress') {
                throw ValidationException::withMessages(['target' => 'Batch Pengolahan harus sedang berjalan.']);
            }
            if ($target instanceof PortioningSession && $target->state->value !== 'in_progress') {
                throw ValidationException::withMessages(['target' => 'Sesi Pemorsian harus sedang berjalan.']);
            }

            $targetColumn = $division === 'processing' ? 'processing_batch_id' : 'portioning_session_id';
            $otherColumn = $division === 'processing' ? 'portioning_session_id' : 'processing_batch_id';
            $assignedTarget = $withdrawal->getAttribute($targetColumn);
            if ($assignedTarget && (int) $assignedTarget !== (int) $target->getKey()) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Hasil Persiapan sudah ditujukan ke pekerjaan lain.',
                ]);
            }

            $withdrawal->forceFill([
                $targetColumn => $target->getKey(),
                $otherColumn => null,
            ])->save();

            return $this->verifyWithdrawal(
                $withdrawal,
                $actor,
                (float) $withdrawal->requested_quantity,
            );
        });
    }

    public function verifyWithdrawal(
        PreparationOutputWithdrawal $withdrawal,
        User $actor,
        float $verifiedQuantity,
        ?string $notes = null,
    ): PreparationOutputWithdrawal {
        $permission = $withdrawal->destination_division === 'processing' ? 'processing.update' : 'portioning.update';
        abort_unless($actor->can('preparation.update') || $actor->can($permission), 403);

        return DB::transaction(function () use ($withdrawal, $actor, $verifiedQuantity, $notes): PreparationOutputWithdrawal {
            $withdrawal = PreparationOutputWithdrawal::query()
                ->whereKey($withdrawal->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $output = PreparationOutput::query()
                ->whereKey($withdrawal->preparation_output_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== PreparationOutputWithdrawal::WAITING) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Pengambilan Hasil Persiapan sudah diproses.',
                ]);
            }

            $requested = (float) $withdrawal->requested_quantity;
            if ($verifiedQuantity <= 0 || $verifiedQuantity > $requested) {
                throw ValidationException::withMessages([
                    'verified_quantity' => 'Jumlah aktual harus lebih dari nol dan tidak boleh melebihi jumlah yang diminta.',
                ]);
            }

            $difference = max(0, $requested - $verifiedQuantity);
            if ($difference > 0) {
                $this->restoreQuantity($output, $difference, $actor);
            }

            $withdrawal->update([
                'verified_quantity' => $verifiedQuantity,
                'status' => PreparationOutputWithdrawal::VERIFIED,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => filled($notes) ? trim($notes) : null,
            ]);

            return $withdrawal->refresh();
        });
    }

    public function rejectWithdrawal(
        PreparationOutputWithdrawal $withdrawal,
        User $actor,
        string $notes,
    ): PreparationOutputWithdrawal {
        abort_unless($actor->can('preparation.update'), 403);

        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($withdrawal, $actor, $notes): PreparationOutputWithdrawal {
            $withdrawal = PreparationOutputWithdrawal::query()
                ->whereKey($withdrawal->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $output = PreparationOutput::query()
                ->whereKey($withdrawal->preparation_output_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== PreparationOutputWithdrawal::WAITING) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Pengambilan Hasil Persiapan sudah diproses.',
                ]);
            }

            $this->restoreQuantity($output, (float) $withdrawal->requested_quantity, $actor);
            $withdrawal->update([
                'verified_quantity' => 0,
                'status' => PreparationOutputWithdrawal::REJECTED,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'review_notes' => trim($notes),
            ]);

            return $withdrawal->refresh();
        });
    }

    private function reserveQuantity(PreparationOutput $output, float $quantity, User $actor): void
    {
        $available = max(0, (float) $output->available_quantity - $quantity);
        $output->update([
            'available_quantity' => $available,
            'state' => $available <= 0
                ? PreparationOutput::DEPLETED
                : PreparationOutput::PARTIALLY_TAKEN,
            'updated_by' => $actor->getKey(),
        ]);
    }

    private function restoreQuantity(PreparationOutput $output, float $quantity, User $actor): void
    {
        $available = min((float) $output->quantity, (float) $output->available_quantity + $quantity);
        $output->update([
            'available_quantity' => $available,
            'state' => $available >= (float) $output->quantity
                ? PreparationOutput::AVAILABLE
                : PreparationOutput::PARTIALLY_TAKEN,
            'updated_by' => $actor->getKey(),
        ]);
    }
}
