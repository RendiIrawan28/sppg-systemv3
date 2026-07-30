<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\ProcessingBatch;
use App\Models\PortioningSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationOutputService
{
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

            abort_unless($permission && $actor->can($permission), 403);

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

            if ($division === 'processing' && empty($data['processing_batch_id'])) {
                throw ValidationException::withMessages([
                    'processing_batch_id' => 'Batch Pengolahan tujuan wajib dipilih.',
                ]);
            }

            if ($division === 'portioning' && empty($data['portioning_session_id'])) {
                throw ValidationException::withMessages([
                    'portioning_session_id' => 'Sesi Pemorsian tujuan wajib dipilih.',
                ]);
            }

            if ($division === 'processing') {
                ProcessingBatch::query()
                    ->where('sppg_unit_id', $output->sppg_unit_id)
                    ->whereIn('state', ['planned', 'in_progress'])
                    ->findOrFail((int) $data['processing_batch_id']);
            } else {
                PortioningSession::query()
                    ->where('sppg_unit_id', $output->sppg_unit_id)
                    ->whereIn('state', ['planned', 'in_progress'])
                    ->findOrFail((int) $data['portioning_session_id']);
            }

            $withdrawal = $output->withdrawals()->create([
                'destination_division' => $division,
                'processing_batch_id' => $division === 'processing' ? $data['processing_batch_id'] : null,
                'portioning_session_id' => $division === 'portioning' ? $data['portioning_session_id'] : null,
                'requested_quantity' => $quantity,
                'verified_quantity' => $quantity,
                'unit_snapshot' => $output->unit_snapshot,
                'status' => PreparationOutputWithdrawal::VERIFIED,
                'taken_by' => $actor->getKey(),
                'taken_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);

            $available = max(0, (float) $output->available_quantity - $quantity);
            $output->update([
                'available_quantity' => $available,
                'state' => $available <= 0
                    ? PreparationOutput::DEPLETED
                    : PreparationOutput::PARTIALLY_TAKEN,
                'updated_by' => $actor->getKey(),
            ]);

            return $withdrawal->refresh();
        });
    }
}
