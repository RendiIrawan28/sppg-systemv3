<?php

namespace App\Services;

use App\Enums\PortioningSessionState;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortioningInputService
{
    public function syncProcessingCompletion(ProcessingBatch $batch, User $actor): ?PortioningSession
    {
        return DB::transaction(function () use ($batch, $actor): ?PortioningSession {
            $batch = ProcessingBatch::query()
                ->with('temperatureLogs')
                ->lockForUpdate()
                ->findOrFail($batch->id);
            if ($batch->state->value !== 'completed') {
                throw ValidationException::withMessages([
                    'state' => 'Hasil Pengolahan belum selesai.',
                ]);
            }
            $session = PortioningSession::query()
                ->where('sppg_unit_id', $batch->sppg_unit_id)
                ->where('processing_batch_id', $batch->id)
                ->lockForUpdate()
                ->first();
            if (! $session) {
                return null;
            }
            if ($session->state !== PortioningSessionState::Planned) {
                throw ValidationException::withMessages([
                    'state' => 'Sesi Pemorsian sudah berjalan dan tidak dapat menerima perubahan hasil Pengolahan.',
                ]);
            }

            $temperature = $batch->temperatureLogs->sortByDesc('checked_at')->first();
            $session->update([
                'received_output_quantity' => $batch->actual_output_quantity,
                'received_output_unit' => $batch->actual_output_unit,
                'received_temperature_celsius' => $temperature?->temperature_celsius,
                'received_by' => null,
                'received_at' => $batch->completed_at ?? now(),
            ]);
            $this->ensureChecklist($session);
            $this->history($session, $actor, 'processing_output_available', $batch->batch_number);

            return $session->refresh();
        });
    }

    public function syncWarehouseWithdrawal(WarehouseWithdrawal $withdrawal, User $actor): ?PortioningSession
    {
        if ($withdrawal->division_code !== 'pemorsian'
            || ! in_array($withdrawal->status, [WarehouseWithdrawal::WAITING, WarehouseWithdrawal::VERIFIED], true)) {
            return null;
        }

        return DB::transaction(function () use ($withdrawal, $actor): PortioningSession {
            $withdrawal = WarehouseWithdrawal::query()->with('items')->lockForUpdate()->findOrFail($withdrawal->id);
            $session = PortioningSession::query()
                ->where('sppg_unit_id', $withdrawal->sppg_unit_id)
                ->lockForUpdate()
                ->find($withdrawal->reference_id);
            if (! $session) {
                throw ValidationException::withMessages(['reference' => 'Sesi Pemorsian tidak ditemukan.']);
            }
            $sourceItemIds = $withdrawal->items->pluck('id');
            $knownItemIds = $session->supplies()
                ->where('source_type', 'warehouse_withdrawal')
                ->whereIn('source_item_id', $sourceItemIds)
                ->pluck('source_item_id');
            if ($sourceItemIds->diff($knownItemIds)->isNotEmpty()
                && ! in_array($session->state, [PortioningSessionState::Planned, PortioningSessionState::InProgress], true)) {
                throw ValidationException::withMessages(['reference' => 'Sesi Pemorsian sudah ditutup dan tidak dapat menerima bahan tambahan.']);
            }
            foreach ($withdrawal->items as $index => $item) {
                $session->supplies()->updateOrCreate([
                    'source_type' => 'warehouse_withdrawal',
                    'source_item_id' => $item->id,
                ], [
                    'source_id' => $withdrawal->id,
                    'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $item->inventory_lot_id,
                    'supply_name' => $item->ingredient_name_snapshot,
                    'quantity' => $item->actual_quantity ?? $item->requested_quantity ?? $item->verified_quantity_kg ?? $item->taken_quantity_kg,
                    'unit_name' => $item->unit_snapshot,
                    'source_reference' => $withdrawal->withdrawal_number,
                    'condition_status' => $item->condition_status,
                    'received_by' => $withdrawal->taken_by,
                    'received_at' => $withdrawal->verified_at ?? $withdrawal->submitted_at,
                    'notes' => $withdrawal->status === WarehouseWithdrawal::VERIFIED
                        ? 'Jumlah aktual telah diverifikasi Gudang.'
                        : 'Jumlah sementara dari pengambilan langsung Divisi Pemorsian.',
                    'sort_order' => $session->supplies()->count() + $index + 1,
                ]);
            }
            $this->ensureChecklist($session);
            $this->history(
                $session,
                $actor,
                $withdrawal->status === WarehouseWithdrawal::VERIFIED ? 'warehouse_supply_verified' : 'warehouse_supply_recorded',
                $withdrawal->withdrawal_number,
            );

            return $session->refresh();
        });
    }

    public function ensureChecklist(PortioningSession $session): void
    {
        if ($session->checklistItems()->exists()) {
            return;
        }
        $items = [
            ['hygiene', 'Petugas memakai APD lengkap dan telah mencuci tangan'],
            ['sanitation', 'Meja, alat saji, wadah, dan timbangan dalam kondisi bersih'],
            ['cross_contamination', 'Produk matang terlindung dari kontaminasi silang'],
            ['portion_standard', 'Porsi kecil dan besar mengikuti standar menu yang aktif'],
            ['special_diet', 'Menu khusus/alergen dipisahkan dan diberi penanda yang jelas'],
            ['packaging', 'Wadah tertutup, bersih, dan tidak rusak sebelum distribusi'],
            ['time_temperature', 'Waktu dan suhu selama pemorsian berada dalam batas aman'],
            ['reconciliation', 'Jumlah porsi per rute telah direkonsiliasi'],
        ];
        foreach ($items as $index => [$category, $name]) {
            $session->checklistItems()->create([
                'category' => $category,
                'item_name' => $name,
                'is_mandatory' => true,
                'result' => 'pending',
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function history(PortioningSession $session, User $actor, string $action, string $reference): void
    {
        $session->histories()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'previous_state' => $session->state->value,
            'new_state' => $session->state->value,
            'notes' => $reference,
            'snapshot' => $session->fresh(['supplies', 'checklistItems'])->toArray(),
        ]);
    }
}
