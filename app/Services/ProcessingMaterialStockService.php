<?php

namespace App\Services;

use App\Enums\ProcessingBatchState;
use App\Models\PreparationOutputWithdrawal;
use App\Models\ProcessingBatch;
use App\Models\ProcessingMaterialStock;
use App\Models\ProcessingMaterialUsage;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingMaterialStockService
{
    public function receiveWarehouseWithdrawal(
        WarehouseWithdrawal $withdrawal,
        User $actor,
    ): void {
        if ($withdrawal->division_code !== 'pengolahan'
            || ! in_array($withdrawal->status, [
                WarehouseWithdrawal::WAITING,
                WarehouseWithdrawal::VERIFIED,
            ], true)) {
            return;
        }

        DB::transaction(function () use ($withdrawal, $actor): void {
            $withdrawal = WarehouseWithdrawal::query()
                ->with(['items.ingredient.measurementUnit', 'items.lot'])
                ->lockForUpdate()
                ->findOrFail($withdrawal->getKey());

            $verified = $withdrawal->status === WarehouseWithdrawal::VERIFIED;
            $sourceItemIds = $withdrawal->items->pluck('id');

            foreach ($withdrawal->items as $item) {
                $quantity = (float) ($verified
                    ? ($item->actual_quantity ?? $item->requested_quantity ?? $item->verified_quantity_kg ?? $item->taken_quantity_kg)
                    : ($item->requested_quantity ?? $item->taken_quantity_kg));

                if ($quantity <= 0) {
                    continue;
                }

                $stock = ProcessingMaterialStock::query()
                    ->where('source_type', 'warehouse')
                    ->where('source_item_id', $item->getKey())
                    ->lockForUpdate()
                    ->first();
                $usedQuantity = $stock
                    ? max(0, (float) $stock->received_quantity - (float) $stock->available_quantity)
                    : 0.0;
                if ($quantity + 0.0001 < $usedQuantity) {
                    throw ValidationException::withMessages([
                        'items' => sprintf(
                            'Jumlah aktual %s tidak boleh lebih kecil dari %s %s yang sudah dipakai Pengolahan.',
                            $item->ingredient_name_snapshot,
                            rtrim(rtrim(number_format($usedQuantity, 4, '.', ''), '0'), '.'),
                            $item->unit_snapshot,
                        ),
                    ]);
                }

                $values = [
                    'sppg_unit_id' => $withdrawal->sppg_unit_id,
                    'source_id' => $withdrawal->getKey(),
                    'ingredient_id' => $item->ingredient_id,
                    'inventory_lot_id' => $item->inventory_lot_id,
                    'material_name' => $item->ingredient_name_snapshot,
                    'measurement_unit_id' => $item->ingredient?->measurement_unit_id,
                    'unit_name' => $item->unit_snapshot,
                    'received_quantity' => $quantity,
                    'available_quantity' => round($quantity - $usedQuantity, 4),
                    'source_reference' => $withdrawal->withdrawal_number,
                    'received_by' => $withdrawal->taken_by ?: $actor->getKey(),
                    'received_at' => $withdrawal->submitted_at ?: now(),
                    'expires_at' => $item->expiry_date_snapshot?->endOfDay(),
                    'status' => $quantity - $usedQuantity > 0.0001
                        ? ProcessingMaterialStock::AVAILABLE
                        : ProcessingMaterialStock::DEPLETED,
                    'notes' => $verified
                        ? 'Jumlah aktual pengambilan telah diverifikasi Gudang.'
                        : 'Bahan langsung tersedia setelah diambil; verifikasi Gudang masih menunggu.',
                ];

                if ($stock) {
                    $stock->update($values);
                } else {
                    ProcessingMaterialStock::query()->create([
                        'source_type' => 'warehouse',
                        'source_item_id' => $item->getKey(),
                        ...$values,
                    ]);
                }
            }

            $staleStocks = ProcessingMaterialStock::query()
                ->where('source_type', 'warehouse')
                ->where('source_id', $withdrawal->getKey())
                ->when($sourceItemIds->isNotEmpty(), fn ($query) => $query->whereNotIn('source_item_id', $sourceItemIds))
                ->lockForUpdate()
                ->get();
            if ($staleStocks->contains(fn (ProcessingMaterialStock $stock): bool => $stock->usages()->exists()
                || (float) $stock->received_quantity - (float) $stock->available_quantity > 0.0001)) {
                throw ValidationException::withMessages([
                    'items' => 'Bahan yang sudah dipakai Pengolahan tidak dapat dihapus dari pengambilan.',
                ]);
            }
            ProcessingMaterialStock::query()->whereKey($staleStocks->pluck('id'))->delete();
        });
    }

    public function receivePreparationWithdrawal(
        PreparationOutputWithdrawal $withdrawal,
        User $actor,
    ): void {
        if ($withdrawal->destination_division !== 'processing'
            || $withdrawal->status !== PreparationOutputWithdrawal::VERIFIED) {
            return;
        }

        DB::transaction(function () use ($withdrawal, $actor): void {
            $withdrawal = PreparationOutputWithdrawal::query()
                ->with('output.ingredient.measurementUnit')
                ->lockForUpdate()
                ->findOrFail($withdrawal->getKey());

            $quantity = (float) $withdrawal->verified_quantity;
            if ($quantity <= 0 || ! $withdrawal->output) {
                return;
            }

            ProcessingMaterialStock::query()->firstOrCreate(
                [
                    'source_type' => 'preparation',
                    'source_item_id' => $withdrawal->getKey(),
                ],
                [
                    'sppg_unit_id' => $withdrawal->output->sppg_unit_id,
                    'source_id' => $withdrawal->preparation_output_id,
                    'ingredient_id' => $withdrawal->output->ingredient_id,
                    'inventory_lot_id' => null,
                    'material_name' => $withdrawal->output->output_name,
                    'measurement_unit_id' => $withdrawal->output->ingredient?->measurement_unit_id,
                    'unit_name' => $withdrawal->unit_snapshot,
                    'received_quantity' => $quantity,
                    'available_quantity' => $quantity,
                    'source_reference' => 'Persiapan #'.$withdrawal->preparation_output_id,
                    'received_by' => $withdrawal->verified_by ?: $actor->getKey(),
                    'received_at' => $withdrawal->verified_at ?: now(),
                    'expires_at' => $withdrawal->output->expires_at,
                    'status' => ProcessingMaterialStock::AVAILABLE,
                    'notes' => 'Hasil Persiapan diterima oleh Divisi Pengolahan.',
                ],
            );
        });
    }

    /**
     * @param  array<int|string, float|int|string|null>  $quantities
     */
    public function syncBatchUsages(
        ProcessingBatch $batch,
        array $quantities,
        User $actor,
    ): ProcessingBatch {
        abort_unless($actor->can('processing.update'), 403);

        return DB::transaction(function () use ($batch, $quantities, $actor): ProcessingBatch {
            $batch = ProcessingBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->getKey());

            if ($batch->state !== ProcessingBatchState::InProgress
                || ! $batch->isReportEditable()) {
                throw ValidationException::withMessages([
                    'materialConsumptions' => 'Pemakaian bahan hanya dapat diubah saat batch Pengolahan sedang berjalan.',
                ]);
            }

            $normalized = collect($quantities)
                ->mapWithKeys(function ($value, $stockId): array {
                    $quantity = is_numeric($value) ? round((float) $value, 4) : 0.0;

                    return [(int) $stockId => $quantity];
                })
                ->filter(fn (float $quantity, int $stockId): bool => $stockId > 0 && $quantity > 0)
                ->all();

            $existing = ProcessingMaterialUsage::query()
                ->where('processing_batch_id', $batch->getKey())
                ->whereNotNull('processing_material_stock_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('processing_material_stock_id');

            $stockIds = collect(array_keys($normalized))
                ->merge($existing->keys())
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $stocks = ProcessingMaterialStock::query()
                ->whereIn('id', $stockIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($stocks->count() !== $stockIds->count()) {
                throw ValidationException::withMessages([
                    'materialConsumptions' => 'Salah satu stok bahan Pengolahan tidak ditemukan.',
                ]);
            }

            // Kembalikan sementara pemakaian batch ini ke saldo agar proses edit
            // dapat mengganti jumlah tanpa menyebabkan stok minus semu.
            foreach ($existing as $stockId => $usage) {
                $stock = $stocks->get((int) $stockId);
                if (! $stock) {
                    continue;
                }

                $stock->available_quantity = round(
                    (float) $stock->available_quantity + (float) $usage->quantity,
                    4,
                );
                $stock->status = ProcessingMaterialStock::AVAILABLE;
                $stock->save();
            }

            foreach ($normalized as $stockId => $quantity) {
                /** @var ProcessingMaterialStock $stock */
                $stock = $stocks->get((int) $stockId);

                if ((int) $stock->sppg_unit_id !== (int) $batch->sppg_unit_id) {
                    throw ValidationException::withMessages([
                        "materialConsumptions.$stockId" => 'Bahan berasal dari unit SPPG lain.',
                    ]);
                }

                if ($stock->expires_at && $stock->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        "materialConsumptions.$stockId" => "{$stock->material_name} sudah melewati batas penggunaan.",
                    ]);
                }

                if ($quantity > (float) $stock->available_quantity + 0.0001) {
                    $shortage = max(0, $quantity - (float) $stock->available_quantity);
                    throw ValidationException::withMessages([
                        "materialConsumptions.$stockId" => sprintf(
                            'Stok %s kurang %s %s. Lakukan pengambilan tambahan dari Gudang/Persiapan terlebih dahulu.',
                            $stock->material_name,
                            rtrim(rtrim(number_format($shortage, 4, '.', ''), '0'), '.'),
                            $stock->unit_name,
                        ),
                    ]);
                }

                $stock->available_quantity = round(
                    (float) $stock->available_quantity - $quantity,
                    4,
                );
                $stock->status = (float) $stock->available_quantity > 0.0001
                    ? ProcessingMaterialStock::AVAILABLE
                    : ProcessingMaterialStock::DEPLETED;
                $stock->save();

                ProcessingMaterialUsage::query()->updateOrCreate(
                    [
                        'processing_batch_id' => $batch->getKey(),
                        'processing_material_stock_id' => $stock->getKey(),
                    ],
                    [
                        'source_type' => 'processing_stock',
                        'source_id' => $stock->getKey(),
                        // Gunakan ID stok global agar unique lama tetap aman walaupun
                        // ID item Gudang dan ID serah-terima Persiapan kebetulan sama.
                        'source_item_id' => $stock->getKey(),
                        'ingredient_id' => $stock->ingredient_id,
                        'inventory_lot_id' => $stock->inventory_lot_id,
                        'material_name' => $stock->material_name,
                        'quantity' => $quantity,
                        'measurement_unit_id' => $stock->measurement_unit_id,
                        'unit_name' => $stock->unit_name,
                        'source_reference' => $stock->source_reference,
                        'condition_status' => 'good',
                        'received_by' => $actor->getKey(),
                        'received_at' => now(),
                        'notes' => 'Pemakaian aktual dari stok bahan global Pengolahan.',
                        'sort_order' => $stock->getKey(),
                    ],
                );
            }

            ProcessingMaterialUsage::query()
                ->where('processing_batch_id', $batch->getKey())
                ->whereNotNull('processing_material_stock_id')
                ->when(
                    array_keys($normalized) !== [],
                    fn ($query) => $query->whereNotIn('processing_material_stock_id', array_keys($normalized)),
                    fn ($query) => $query,
                )
                ->delete();

            $batch->histories()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'material_stock_usage_updated',
                'from_state' => $batch->state->value,
                'to_state' => $batch->state->value,
                'notes' => 'Pemakaian bahan batch disinkronkan dengan stok global Pengolahan.',
                'snapshot' => $batch->fresh('materialUsages')->toArray(),
            ]);

            return $batch->refresh();
        });
    }
}
