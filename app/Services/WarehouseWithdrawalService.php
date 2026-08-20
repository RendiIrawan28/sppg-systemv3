<?php

namespace App\Services;

use App\Enums\PortioningSessionState;
use App\Enums\ProcessingBatchState;
use App\Models\FieldDistributionPlan;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Models\WarehouseWithdrawalItem;
use App\Support\DivisionRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseWithdrawalService
{
    public function __construct(private readonly InventoryUnitService $units) {}

    public function createMobileDraft(
        int $unitId,
        string $divisionCode,
        string $referenceSelection,
        ?string $purposeReference,
        ?string $shift,
        ?string $notes,
        User $actor,
    ): WarehouseWithdrawal {
        $permission = match ($divisionCode) {
            'persiapan' => 'preparation.update',
            'pengolahan' => 'processing.update',
            'pemorsian' => 'portioning.update',
            default => null,
        };
        abort_unless($permission && $actor->can($permission), 403);

        $actorDivisions = collect($actor->getRoleNames())
            ->map(fn (string $role): ?string => DivisionRole::divisionCodeForRole($role))
            ->filter()
            ->unique();
        $privileged = $actor->is_super_admin || $actor->hasAnyRole(['super_admin', 'admin_sppg', 'kepala_sppg']);
        if (! $privileged && ! $actorDivisions->contains($divisionCode)) {
            throw ValidationException::withMessages(['division' => 'Divisi pengguna tidak sesuai dengan pengambilan Gudang.']);
        }

        if (! preg_match('/^(record|plan):(\d+)$/', $referenceSelection, $matches)) {
            throw ValidationException::withMessages(['fields.reference_selection' => 'Referensi pekerjaan tidak valid.']);
        }

        $selectionType = $matches[1];
        $referenceId = (int) $matches[2];
        $referenceType = match ($divisionCode) {
            'persiapan' => 'field_plan',
            'pengolahan' => 'processing_batch',
            'pemorsian' => 'portioning_session',
        };

        if ($selectionType === 'plan') {
            if (in_array($divisionCode, ['pengolahan', 'pemorsian'], true)) {
                throw ValidationException::withMessages([
                    'fields.reference_selection' => $divisionCode === 'pengolahan'
                        ? 'Mulai produksi dari modul Pengolahan sebelum mengambil bahan Gudang.'
                        : 'Mulai proses dari modul Pemorsian sebelum mengambil barang Gudang.',
                ]);
            }
            $plan = FieldDistributionPlan::query()
                ->where('sppg_unit_id', $unitId)
                ->where('status', 'activated')
                ->find($referenceId);
            if (! $plan) {
                throw ValidationException::withMessages(['fields.reference_selection' => 'Rencana distribusi aktif tidak ditemukan.']);
            }

            $referenceId = match ($divisionCode) {
                'persiapan' => $plan->getKey(),
                'pengolahan' => app(FieldOperationalPlanGenerator::class)
                    ->generateProcessingBatch($plan, $actor)->getKey(),
                'pemorsian' => app(FieldOperationalPlanGenerator::class)
                    ->generatePortioningSession($plan, $actor)->getKey(),
            };
        }

        [$referenceType, $referenceNumber] = $this->resolveReference(
            $unitId,
            $divisionCode,
            $referenceType,
            $referenceId,
        );

        return WarehouseWithdrawal::query()->create([
            'sppg_unit_id' => $unitId,
            'withdrawal_date' => today(),
            'division_code' => $divisionCode,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number_snapshot' => $referenceNumber,
            'purpose_reference' => trim((string) $purposeReference) ?: $referenceNumber,
            'shift' => filled($shift) ? trim((string) $shift) : null,
            'status' => WarehouseWithdrawal::DRAFT,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            'taken_by' => $actor->getKey(),
        ]);
    }

    public function submitMobileDraft(WarehouseWithdrawal $withdrawal, User $actor): WarehouseWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor): WarehouseWithdrawal {
            $withdrawal = WarehouseWithdrawal::query()
                ->lockForUpdate()
                ->with(['items.lot.ingredient'])
                ->findOrFail($withdrawal->getKey());

            if (! $withdrawal->isEditable() || (int) $withdrawal->taken_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['status' => 'Pengambilan tidak dapat diajukan oleh pengguna ini.']);
            }
            if ($withdrawal->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Minimal satu bahan wajib ditambahkan sebelum pengambilan diajukan.']);
            }
            if ($withdrawal->items->pluck('inventory_lot_id')->filter()->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'Lot yang sama cukup dicatat satu kali.']);
            }

            [$referenceType, $referenceNumber] = $this->resolveReference(
                (int) $withdrawal->sppg_unit_id,
                (string) $withdrawal->division_code,
                (string) $withdrawal->reference_type,
                (int) $withdrawal->reference_id,
            );

            $lots = InventoryLot::query()
                ->with('ingredient.measurementUnit')
                ->where('sppg_unit_id', $withdrawal->sppg_unit_id)
                ->whereIn('id', $withdrawal->items->pluck('inventory_lot_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($lots->count() !== $withdrawal->items->count()) {
                throw ValidationException::withMessages(['items' => 'Salah satu lot tidak tersedia atau bukan milik unit ini.']);
            }

            $requestedByLot = collect();
            foreach ($withdrawal->items as $item) {
                $lot = $lots->get($item->inventory_lot_id);
                $quantity = (float) $item->requested_quantity;
                $this->assertLotCanBeWithdrawn($lot, $quantity, $this->availableQuantity($lot, $withdrawal->getKey()));
                if (blank($item->photo_path)) {
                    throw ValidationException::withMessages(['items' => "Foto pengambilan lot {$lot->lot_number} wajib dilampirkan."]);
                }
                if (in_array($lot->storage_type, ['freezer', 'chiller'], true)
                    && ! is_numeric($item->pickup_temperature_celsius)) {
                    throw ValidationException::withMessages(['items' => "Suhu pengambilan lot {$lot->lot_number} wajib dicatat."]);
                }

                $item->forceFill([
                    'ingredient_id' => $lot->ingredient_id,
                    'ingredient_name_snapshot' => $lot->ingredient?->name ?: 'Bahan',
                    'lot_number_snapshot' => $lot->lot_number,
                    'expiry_date_snapshot' => $lot->expired_date,
                    'unit_snapshot' => $lot->unit_snapshot,
                    'taken_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $quantity),
                ])->save();
                $requestedByLot->put($lot->getKey(), $quantity);
            }

            $this->assertFefoAllocation(
                (int) $withdrawal->sppg_unit_id,
                $lots,
                $requestedByLot,
                $withdrawal->getKey(),
            );
            $withdrawal->forceFill([
                'reference_type' => $referenceType,
                'reference_number_snapshot' => $referenceNumber,
            ])->save();

            return $this->submit($withdrawal->refresh(), $actor);
        });
    }

    public function createMobileDraftItem(
        WarehouseWithdrawal $withdrawal,
        int $inventoryLotId,
        float $quantity,
        ?float $pickupTemperature,
        string $photoPath,
        ?string $notes,
        User $actor,
    ): WarehouseWithdrawalItem {
        return DB::transaction(function () use (
            $withdrawal, $inventoryLotId, $quantity, $pickupTemperature, $photoPath, $notes, $actor,
        ): WarehouseWithdrawalItem {
            $withdrawal = WarehouseWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());
            if (! $withdrawal->isEditable() || (int) $withdrawal->taken_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['status' => 'Pengambilan tidak dapat diubah oleh pengguna ini.']);
            }
            if ($withdrawal->items()->where('inventory_lot_id', $inventoryLotId)->exists()) {
                throw ValidationException::withMessages(['fields.inventory_lot_id' => 'Lot ini sudah ditambahkan dalam pengambilan.']);
            }

            $lot = InventoryLot::query()
                ->with('ingredient.measurementUnit')
                ->where('sppg_unit_id', $withdrawal->sppg_unit_id)
                ->lockForUpdate()
                ->findOrFail($inventoryLotId);
            $this->assertLotCanBeWithdrawn($lot, $quantity, $this->availableQuantity($lot, $withdrawal->getKey()));
            if (in_array($lot->storage_type, ['freezer', 'chiller'], true) && $pickupTemperature === null) {
                throw ValidationException::withMessages(['fields.pickup_temperature_celsius' => 'Suhu pengambilan wajib diisi untuk barang freezer/chiller.']);
            }

            return $withdrawal->items()->create([
                'ingredient_id' => $lot->ingredient_id,
                'inventory_lot_id' => $lot->id,
                'ingredient_name_snapshot' => $lot->ingredient?->name ?: 'Bahan',
                'lot_number_snapshot' => $lot->lot_number,
                'expiry_date_snapshot' => $lot->expired_date,
                'unit_snapshot' => $lot->unit_snapshot,
                'requested_quantity' => $quantity,
                'pickup_temperature_celsius' => $pickupTemperature,
                'photo_path' => $photoPath,
                'taken_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $quantity),
                'notes' => filled($notes) ? trim($notes) : null,
            ]);
        });
    }

    /**
     * @param  array<int, array{inventory_lot_id: int|string, quantity: float|int|string, photo_path: string, pickup_temperature_celsius?: float|int|string|null}>  $rows
     */
    public function createAndSubmit(
        int $unitId,
        string $divisionCode,
        string $referenceType,
        int $referenceId,
        string $purposeReference,
        ?string $notes,
        array $rows,
        User $actor,
    ): WarehouseWithdrawal {
        return DB::transaction(function () use ($unitId, $divisionCode, $referenceType, $referenceId, $purposeReference, $notes, $rows, $actor): WarehouseWithdrawal {
            if (! in_array($divisionCode, ['persiapan', 'pengolahan', 'pemorsian'], true)) {
                throw ValidationException::withMessages(['division' => 'Divisi tidak diizinkan mengambil bahan langsung dari Gudang.']);
            }

            [$referenceType, $referenceNumber] = $this->resolveReference($unitId, $divisionCode, $referenceType, $referenceId);

            $rowCollection = collect($rows);
            $requestedByLot = $rowCollection->mapWithKeys(
                fn (array $row): array => [(int) $row['inventory_lot_id'] => (float) $row['quantity']],
            );

            if ($requestedByLot->count() !== $rowCollection->count()) {
                throw ValidationException::withMessages(['rows' => 'Lot yang sama cukup dicatat satu kali dalam satu pengambilan.']);
            }
            if ($requestedByLot->isEmpty() || $requestedByLot->contains(fn (float $quantity): bool => $quantity <= 0)) {
                throw ValidationException::withMessages(['rows' => 'Minimal satu lot dengan jumlah lebih dari nol wajib dipilih.']);
            }

            $lots = InventoryLot::query()
                ->with('ingredient.measurementUnit')
                ->where('sppg_unit_id', $unitId)
                ->whereIn('id', $requestedByLot->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lots->count() !== $requestedByLot->count()) {
                throw ValidationException::withMessages(['rows' => 'Salah satu lot tidak berasal dari unit SPPG ini.']);
            }

            foreach ($requestedByLot as $lotId => $quantity) {
                $lot = $lots->get($lotId);
                $this->assertLotCanBeWithdrawn($lot, $quantity, $this->availableQuantity($lot));
                $row = $rowCollection->firstWhere('inventory_lot_id', $lotId);
                if (blank($row['photo_path'] ?? null)) {
                    throw ValidationException::withMessages(['rows' => "Foto pengambilan lot {$lot->lot_number} wajib dilampirkan."]);
                }
                if (in_array($lot->storage_type, ['freezer', 'chiller'], true)
                    && ! is_numeric($row['pickup_temperature_celsius'] ?? null)) {
                    throw ValidationException::withMessages(['rows' => "Suhu saat pengambilan lot {$lot->lot_number} wajib dicatat."]);
                }
            }
            $this->assertFefoAllocation($unitId, $lots, $requestedByLot);

            $withdrawal = WarehouseWithdrawal::query()->create([
                'sppg_unit_id' => $unitId,
                'withdrawal_date' => today(),
                'division_code' => $divisionCode,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number_snapshot' => $referenceNumber,
                'purpose_reference' => trim($purposeReference) ?: $referenceNumber,
                'status' => WarehouseWithdrawal::DRAFT,
                'notes' => filled($notes) ? trim($notes) : null,
                'taken_by' => $actor->id,
            ]);

            foreach ($requestedByLot as $lotId => $quantity) {
                $lot = $lots->get($lotId);
                $row = $rowCollection->firstWhere('inventory_lot_id', $lotId);
                $pickupTemperature = $row['pickup_temperature_celsius'] ?? null;
                $withdrawal->items()->create([
                    'ingredient_id' => $lot->ingredient_id,
                    'inventory_lot_id' => $lot->id,
                    'ingredient_name_snapshot' => $lot->ingredient->name,
                    'lot_number_snapshot' => $lot->lot_number,
                    'expiry_date_snapshot' => $lot->expired_date,
                    'unit_snapshot' => $lot->unit_snapshot,
                    'requested_quantity' => $quantity,
                    'pickup_temperature_celsius' => filled($pickupTemperature) ? (float) $pickupTemperature : null,
                    'photo_path' => $row['photo_path'],
                    'taken_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $quantity),
                ]);
            }

            return $this->submit($withdrawal, $actor);
        });
    }

    public function submit(WarehouseWithdrawal $withdrawal, User $actor): WarehouseWithdrawal
    {
        if (! $withdrawal->isEditable() || (int) $withdrawal->taken_by !== (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'Transaksi tidak dapat diajukan oleh pengguna ini.']);
        }
        if (! in_array($withdrawal->division_code, ['persiapan', 'pengolahan', 'pemorsian'], true) || ! $withdrawal->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'Divisi dan minimal satu bahan wajib diisi.']);
        }
        $withdrawal->update(['status' => WarehouseWithdrawal::WAITING, 'submitted_at' => now(), 'decision_notes' => null]);
        $withdrawal = $withdrawal->refresh()->load('items');
        app(PreparationSessionService::class)->createFromWithdrawal($withdrawal);
        app(ProcessingInputService::class)->syncWarehouseWithdrawal($withdrawal, $actor);
        app(PortioningInputService::class)->syncWarehouseWithdrawal($withdrawal, $actor);

        return $withdrawal;
    }

    /** @param array<int, float|int|string> $actualQuantities */
    public function verify(WarehouseWithdrawal $withdrawal, User $actor, array $actualQuantities = []): WarehouseWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor, $actualQuantities): WarehouseWithdrawal {
            $withdrawal = WarehouseWithdrawal::query()->lockForUpdate()->with('items')->findOrFail($withdrawal->id);
            if ($withdrawal->status !== WarehouseWithdrawal::WAITING) {
                throw ValidationException::withMessages(['status' => 'Pengambilan tidak sedang menunggu verifikasi Gudang.']);
            }

            $lots = InventoryLot::query()
                ->with('ingredient.measurementUnit')
                ->whereIn('id', $withdrawal->items->pluck('inventory_lot_id')->filter())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $quantities = $withdrawal->items
                ->groupBy('inventory_lot_id')
                ->map(fn (Collection $items): float => (float) $items->sum(
                    fn ($item): float => (float) ($actualQuantities[$item->id] ?? $item->actual_quantity ?? $item->requested_quantity ?? $item->taken_quantity_kg),
                ));

            if ($lots->count() !== $quantities->count()) {
                throw ValidationException::withMessages(['items' => 'Salah satu lot pengambilan tidak lagi tersedia.']);
            }
            foreach ($quantities as $lotId => $quantity) {
                $lot = $lots->get($lotId);
                if ((int) $lot->sppg_unit_id !== (int) $withdrawal->sppg_unit_id) {
                    throw ValidationException::withMessages(['items' => 'Lot pengambilan berasal dari unit SPPG lain.']);
                }
                $this->assertLotCanBeWithdrawn($lot, $quantity, $this->availableQuantity($lot, $withdrawal->id));
            }
            $this->assertFefoAllocation((int) $withdrawal->sppg_unit_id, $lots, $quantities, $withdrawal->id);

            foreach ($withdrawal->items as $item) {
                $lot = $lots->get($item->inventory_lot_id);
                $quantity = (float) ($actualQuantities[$item->id] ?? $item->actual_quantity ?? $item->requested_quantity ?? $item->taken_quantity_kg);
                if ($lot->sppg_unit_id !== $withdrawal->sppg_unit_id || $lot->ingredient_id !== $item->ingredient_id) {
                    throw ValidationException::withMessages(['items' => "Lot untuk {$item->ingredient_name_snapshot} tidak sesuai."]);
                }
                if ($lot->status !== InventoryLot::AVAILABLE || ($lot->expired_date && $lot->expired_date->isBefore(today()))) {
                    throw ValidationException::withMessages(['items' => "Lot {$item->ingredient_name_snapshot} tidak tersedia atau kedaluwarsa."]);
                }
                if ($quantity <= 0 || $quantity > (float) $lot->balance_quantity) {
                    throw ValidationException::withMessages(['items' => "Saldo lot {$item->ingredient_name_snapshot} tidak mencukupi."]);
                }

                $lot->balance_quantity = (float) $lot->balance_quantity - $quantity;
                $lot->balance_quantity_kg = $this->units->legacyKilograms($lot->ingredient, (float) $lot->balance_quantity);
                if ((float) $lot->balance_quantity <= 0.0001) {
                    $lot->status = InventoryLot::DEPLETED;
                }
                $lot->save();
                $item->update([
                    'actual_quantity' => $quantity,
                    'verified_quantity_kg' => $this->units->legacyKilograms($lot->ingredient, $quantity),
                ]);
                StockMovement::create([
                    'sppg_unit_id' => $withdrawal->sppg_unit_id, 'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $lot->id, 'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $lot->unit_snapshot, 'movement_type' => StockMovement::TYPE_HANDOVER,
                    'movement_date' => $withdrawal->withdrawal_date, 'quantity_in_kg' => 0,
                    'quantity_out_kg' => $this->units->legacyKilograms($lot->ingredient, $quantity),
                    'quantity_in' => 0, 'quantity_out' => $quantity, 'source_type' => WarehouseWithdrawal::class,
                    'source_id' => $withdrawal->id, 'reference_number' => $withdrawal->withdrawal_number,
                    'supplier_batch_number' => $lot->lot_number, 'expired_date' => $lot->expired_date,
                    'notes' => 'Diambil langsung oleh Divisi '.ucfirst($withdrawal->division_code), 'created_by' => $actor->id,
                ]);
            }
            $withdrawal->update(['status' => WarehouseWithdrawal::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now()]);
            app(PreparationSessionService::class)->createFromWithdrawal($withdrawal->refresh()->load('items'));
            app(ProcessingInputService::class)->syncWarehouseWithdrawal($withdrawal->refresh()->load('items'), $actor);
            app(PortioningInputService::class)->syncWarehouseWithdrawal($withdrawal->refresh()->load('items'), $actor);

            return $withdrawal->refresh();
        });
    }

    public function requestRevision(WarehouseWithdrawal $withdrawal, User $actor, string $reason): WarehouseWithdrawal
    {
        if ($withdrawal->status !== WarehouseWithdrawal::WAITING || blank($reason)) {
            throw ValidationException::withMessages(['decisionNotes' => 'Alasan koreksi wajib diisi.']);
        }
        $withdrawal->update(['status' => WarehouseWithdrawal::REVISION, 'verified_by' => $actor->id, 'decision_notes' => $reason]);

        return $withdrawal->refresh();
    }

    public function reject(WarehouseWithdrawal $withdrawal, User $actor, string $reason): WarehouseWithdrawal
    {
        if ($withdrawal->status !== WarehouseWithdrawal::WAITING || blank($reason)) {
            throw ValidationException::withMessages(['decisionNotes' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($withdrawal, $actor, $reason): WarehouseWithdrawal {
            $withdrawal = WarehouseWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            $this->removeProvisionalDivisionInput($withdrawal);
            $withdrawal->update(['status' => WarehouseWithdrawal::REJECTED, 'verified_by' => $actor->id, 'rejected_at' => now(), 'decision_notes' => $reason]);

            return $withdrawal->refresh();
        });
    }

    private function removeProvisionalDivisionInput(WarehouseWithdrawal $withdrawal): void
    {
        if ($withdrawal->division_code === 'persiapan') {
            $session = PreparationSession::query()
                ->where('warehouse_withdrawal_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();
            if (! $session) {
                return;
            }
            if ($session->state !== 'planned') {
                throw ValidationException::withMessages(['decisionNotes' => 'Persiapan sudah dimulai. Catat jumlah aktual dan verifikasi agar stok tetap sesuai.']);
            }
            $session->items()->delete();
            $session->delete();

            return;
        }

        if ($withdrawal->division_code === 'pengolahan') {
            $batch = ProcessingBatch::query()->lockForUpdate()->find($withdrawal->reference_id);
            if (! $batch) {
                return;
            }
            if (! in_array($batch->state, [ProcessingBatchState::Planned, ProcessingBatchState::InProgress], true)) {
                throw ValidationException::withMessages(['decisionNotes' => 'Pengolahan sudah ditutup. Koreksi bahan tidak dapat dilakukan.']);
            }
            $batch->materialUsages()
                ->where('source_type', 'warehouse_withdrawal')
                ->where('source_id', $withdrawal->id)
                ->delete();

            return;
        }

        if ($withdrawal->division_code === 'pemorsian') {
            $session = PortioningSession::query()->lockForUpdate()->find($withdrawal->reference_id);
            if (! $session) {
                return;
            }
            if (! in_array($session->state, [PortioningSessionState::Planned, PortioningSessionState::InProgress], true)) {
                throw ValidationException::withMessages(['decisionNotes' => 'Pemorsian sudah ditutup. Koreksi barang tidak dapat dilakukan.']);
            }
            $session->supplies()
                ->where('source_type', 'warehouse_withdrawal')
                ->where('source_id', $withdrawal->id)
                ->delete();
        }
    }

    private function assertLotCanBeWithdrawn(InventoryLot $lot, float $quantity, float $available): void
    {
        if ($lot->status !== InventoryLot::AVAILABLE || ($lot->expired_date && $lot->expired_date->isBefore(today()))) {
            throw ValidationException::withMessages(['items' => "Lot {$lot->lot_number} tidak tersedia atau sudah kedaluwarsa."]);
        }
        if ($quantity <= 0 || $quantity > $available + 0.0001) {
            throw ValidationException::withMessages(['items' => "Saldo lot {$lot->lot_number} tidak mencukupi."]);
        }
    }

    private function assertFefoAllocation(int $unitId, Collection $selectedLots, Collection $requestedByLot, ?int $excludeWithdrawalId = null): void
    {
        foreach ($selectedLots->groupBy('ingredient_id') as $ingredientId => $ingredientLots) {
            $availableLots = InventoryLot::query()
                ->where('sppg_unit_id', $unitId)
                ->where('ingredient_id', $ingredientId)
                ->where('status', InventoryLot::AVAILABLE)
                ->where('balance_quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('expired_date')->orWhereDate('expired_date', '>=', today()))
                ->orderByRaw('expired_date IS NULL')
                ->orderBy('expired_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($availableLots as $lot) {
                if ($this->availableQuantity($lot, $excludeWithdrawalId) <= 0.0001) {
                    continue;
                }
                $requested = (float) ($requestedByLot->get($lot->id) ?? 0);
                if ($requested <= 0 && $requestedByLot->keys()->intersect(
                    $availableLots->skipUntil(fn (InventoryLot $candidate): bool => $candidate->is($lot))->skip(1)->pluck('id'),
                )->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => "Gunakan lot {$lot->lot_number} terlebih dahulu sesuai FEFO/FIFO."]);
                }
                if ($requested > 0 && $requested + 0.0001 < $this->availableQuantity($lot, $excludeWithdrawalId)
                    && $requestedByLot->keys()->intersect(
                        $availableLots->skipUntil(fn (InventoryLot $candidate): bool => $candidate->is($lot))->skip(1)->pluck('id'),
                    )->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => "Habiskan lot {$lot->lot_number} sebelum mengambil lot berikutnya."]);
                }
            }
        }
    }

    private function availableQuantity(InventoryLot $lot, ?int $excludeWithdrawalId = null): float
    {
        $reserved = DB::table('warehouse_withdrawal_items')
            ->join('warehouse_withdrawals', 'warehouse_withdrawals.id', '=', 'warehouse_withdrawal_items.warehouse_withdrawal_id')
            ->where('warehouse_withdrawal_items.inventory_lot_id', $lot->id)
            ->where('warehouse_withdrawals.status', WarehouseWithdrawal::WAITING)
            ->when($excludeWithdrawalId, fn ($query) => $query->where('warehouse_withdrawals.id', '!=', $excludeWithdrawalId))
            ->sum('warehouse_withdrawal_items.requested_quantity');

        return max(0, (float) $lot->balance_quantity - (float) $reserved);
    }

    /** @return array{string, string} */
    private function resolveReference(int $unitId, string $divisionCode, string $type, int $id): array
    {
        $definition = match ($divisionCode) {
            'persiapan' => [FieldDistributionPlan::class, 'field_plan', 'plan_number', 'status', ['activated']],
            'pengolahan' => [ProcessingBatch::class, 'processing_batch', 'batch_number', 'state', ['in_progress']],
            'pemorsian' => [PortioningSession::class, 'portioning_session', 'session_number', 'state', ['in_progress']],
            default => throw ValidationException::withMessages(['reference' => 'Divisi tidak memiliki referensi produksi yang valid.']),
        };

        [$model, $expectedType, $numberColumn, $stateColumn, $allowedStates] = $definition;
        if ($type !== $expectedType) {
            throw ValidationException::withMessages(['reference' => 'Jenis referensi tidak sesuai dengan divisi pengambil.']);
        }

        $record = $model::query()
            ->where('sppg_unit_id', $unitId)
            ->whereIn($stateColumn, $allowedStates)
            ->find($id);
        if (! $record) {
            throw ValidationException::withMessages(['reference' => 'Rencana produksi tidak aktif atau tidak ditemukan.']);
        }

        return [$expectedType, (string) $record->{$numberColumn}];
    }
}
