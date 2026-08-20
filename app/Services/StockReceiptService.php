<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\ProcurementRequest;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockReceiptService
{
    /** @param array<string,mixed> $data */
    public function updateInspection(StockReceiptItem $item, array $data): StockReceiptItem
    {
        $received = (float) $data['received_quantity'];
        $accepted = (float) $data['accepted_quantity'];
        $rejected = (float) $data['rejected_quantity'];
        if ($received < 0 || $accepted < 0 || $rejected < 0) {
            throw ValidationException::withMessages(['quantities' => 'Jumlah penerimaan tidak boleh kurang dari nol.']);
        }
        if ($accepted + $rejected > $received + 0.0001) {
            throw ValidationException::withMessages([
                'accepted_quantity' => "Jumlah baik + ditolak untuk {$item->ingredient_name_snapshot} melebihi jumlah diterima.",
            ]);
        }

        $ordered = (float) $item->ordered_quantity;
        $orderedKg = (float) $item->ordered_quantity_kg;
        $kgRatio = $ordered > 0 ? $orderedKg / $ordered : 0;
        $qualityStatus = match (true) {
            $accepted > 0 && $rejected > 0 => 'partial',
            $accepted > 0 => 'accepted',
            $rejected > 0 => 'rejected',
            default => 'pending',
        };
        $updates = [
            'received_quantity' => $received,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rejected,
            'received_quantity_kg' => round($received * $kgRatio, 4),
            'accepted_quantity_kg' => round($accepted * $kgRatio, 4),
            'rejected_quantity_kg' => round($rejected * $kgRatio, 4),
            'quality_status' => $qualityStatus,
            'quality_notes' => trim((string) ($data['quality_notes'] ?? '')) ?: null,
        ];
        foreach (['supplier_batch_number', 'expired_date', 'received_temperature_celsius'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = filled($data[$field]) ? $data[$field] : null;
            }
        }
        $item->update($updates);

        return $item->refresh();
    }

    public function createFromProcurementRequest(ProcurementRequest $request): StockReceipt
    {
        return $this->createGroupedFromProcurementRequest($request)->firstOrFail();
    }

    /** @return Collection<int, StockReceipt> */
    public function createGroupedFromProcurementRequest(ProcurementRequest $request): Collection
    {
        if ($request->status !== ProcurementRequest::STATUS_ORDERED) {
            throw new InvalidArgumentException('Penerimaan bahan hanya dapat dibuat dari permintaan yang sudah dipesan Gudang.');
        }

        return DB::transaction(function () use ($request): Collection {
            $request->loadMissing(['warehouse', 'items.nonFoodItem']);

            if ($request->items->isEmpty()) {
                throw new InvalidArgumentException('Pesanan belum memiliki item untuk diterima.');
            }

            if ($request->items->contains(fn ($item): bool => blank($item->supplier_id))) {
                throw new InvalidArgumentException('Seluruh item harus memiliki supplier sebelum dokumen penerimaan dibuat.');
            }


            $isNonFood = $request->warehouse?->type === Warehouse::TYPE_NON_FOOD;
            $invalidReference = $request->items->contains(fn ($item): bool => $isNonFood
                ? blank($item->non_food_item_id) || filled($item->ingredient_id)
                : blank($item->ingredient_id) || filled($item->non_food_item_id));
            if ($invalidReference || ($isNonFood !== ($request->procurement_type === Warehouse::TYPE_NON_FOOD))) {
                throw new InvalidArgumentException('Jenis barang, jenis pengadaan, dan Gudang tujuan tidak konsisten.');
            }

            return $request->items
                ->groupBy('supplier_id')
                ->map(function (Collection $items, int|string $supplierId) use ($request): StockReceipt {
                    $receipt = StockReceipt::query()->firstOrCreate([
                        'procurement_request_id' => $request->id,
                        'supplier_id' => (int) $supplierId,
                    ], [
                        'sppg_unit_id' => $request->sppg_unit_id,
                        'warehouse_id' => $request->warehouse_id,
                        'receipt_date' => now()->toDateString(),
                        'status' => StockReceipt::STATUS_DRAFT,
                        'created_by' => auth()->id(),
                    ]);

                    foreach ($items as $item) {
                        $ordered = (float) ($item->approved_quantity ?: $item->requested_quantity ?: 0);
                        $orderedKg = (float) ($item->approved_quantity_kg ?: $item->requested_quantity_kg ?: 0);

                        $receipt->items()->updateOrCreate([
                            'procurement_request_item_id' => $item->id,
                        ], [
                            'ingredient_id' => $item->ingredient_id,
                            'non_food_item_id' => $item->non_food_item_id,
                            'supplier_id' => $item->supplier_id,
                            'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                            'unit_snapshot' => $item->unit_snapshot ?: 'unit',
                            'ordered_quantity' => $ordered,
                            'received_quantity' => $ordered,
                            'accepted_quantity' => $ordered,
                            'rejected_quantity' => 0,
                            'ordered_quantity_kg' => $orderedKg,
                            'received_quantity_kg' => $orderedKg,
                            'accepted_quantity_kg' => $orderedKg,
                            'rejected_quantity_kg' => 0,
                            'quality_status' => 'pending',
                        ]);
                    }

                    return $receipt->refresh();
                })
                ->values();
        });
    }

    public function receive(StockReceipt $receipt): StockReceipt
    {
        if (! $receipt->isEditable()) {
            throw new InvalidArgumentException('Penerimaan bahan ini sudah dikunci.');
        }

        if (! $receipt->items()->exists()) {
            throw new InvalidArgumentException('Penerimaan bahan belum memiliki item.');
        }

        $receipt->loadMissing('items.photos');
        $itemWithoutPhoto = $receipt->items->first(fn (StockReceiptItem $item): bool => $item->photos->isEmpty());
        if ($itemWithoutPhoto) {
            throw ValidationException::withMessages([
                'documentation' => "Dokumentasi {$itemWithoutPhoto->ingredient_name_snapshot} wajib diunggah minimal satu foto.",
            ]);
        }

        return DB::transaction(function () use ($receipt): StockReceipt {
            if (blank($receipt->warehouse_id)) {
                $receipt->forceFill([
                    'warehouse_id' => Warehouse::forUnit((int) $receipt->sppg_unit_id, Warehouse::TYPE_FOOD)->getKey(),
                ])->save();
            }
            $receipt->load(['warehouse', 'items.nonFoodItem']);

            if (! $receipt->warehouse || ! $receipt->warehouse->is_active
                || (int) $receipt->warehouse->sppg_unit_id !== (int) $receipt->sppg_unit_id) {
                throw new InvalidArgumentException('Gudang penerimaan tidak aktif atau berasal dari unit lain.');
            }
            $isNonFood = $receipt->warehouse->type === Warehouse::TYPE_NON_FOOD;

            foreach ($receipt->items as $item) {
                if ($isNonFood
                    ? blank($item->non_food_item_id) || filled($item->ingredient_id)
                    : blank($item->ingredient_id) || filled($item->non_food_item_id)) {
                    throw new InvalidArgumentException("Jenis barang {$item->ingredient_name_snapshot} tidak sesuai dengan Gudang penerimaan.");
                }
                $acceptedKg = (float) $item->accepted_quantity_kg;
                $unit = $item->unit_snapshot ?: 'unit';
                $accepted = (float) ($item->accepted_quantity ?? ($unit === 'kg' ? $acceptedKg : 0));

                if ($item->quality_status === 'pending') {
                    throw new InvalidArgumentException("Status kualitas {$item->ingredient_name_snapshot} belum diperiksa.");
                }
                if ($item->expired_date && $item->expired_date->isBefore(today())) {
                    throw new InvalidArgumentException("{$item->ingredient_name_snapshot} sudah kedaluwarsa dan tidak boleh masuk stok.");
                }
                if ($item->quality_status === 'rejected' && $accepted > 0) {
                    throw new InvalidArgumentException('Barang ditolak tidak boleh memiliki jumlah diterima baik.');
                }

                if ($receipt->warehouse?->type === Warehouse::TYPE_NON_FOOD) {
                    if (! $item->nonFoodItem) {
                        throw new InvalidArgumentException("Master Non-Pangan {$item->ingredient_name_snapshot} tidak ditemukan.");
                    }
                    if ($item->nonFoodItem->tracks_lot && blank($item->supplier_batch_number)) {
                        throw new InvalidArgumentException("Nomor lot {$item->ingredient_name_snapshot} wajib diisi.");
                    }
                    if ($item->nonFoodItem->tracks_expiry && blank($item->expired_date)) {
                        throw new InvalidArgumentException("Tanggal kedaluwarsa {$item->ingredient_name_snapshot} wajib diisi.");
                    }
                }

                if ($accepted <= 0) {
                    continue;
                }

                $lot = InventoryLot::query()->updateOrCreate(
                    ['stock_receipt_item_id' => $item->id],
                    [
                        'sppg_unit_id' => $receipt->sppg_unit_id, 'warehouse_id' => $receipt->warehouse_id,
                        'ingredient_id' => $item->ingredient_id, 'non_food_item_id' => $item->non_food_item_id,
                        'unit_snapshot' => $unit, 'initial_quantity' => $accepted, 'balance_quantity' => $accepted,
                        'lot_number' => $item->supplier_batch_number, 'expired_date' => $item->expired_date,
                        'location_name' => $item->nonFoodItem?->default_location ?: 'Gudang Utama', 'status' => InventoryLot::AVAILABLE,
                        'initial_quantity_kg' => $acceptedKg, 'balance_quantity_kg' => $acceptedKg,
                    ],
                );

                StockMovement::query()->create([
                    'sppg_unit_id' => $receipt->sppg_unit_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'ingredient_id' => $item->ingredient_id,
                    'non_food_item_id' => $item->non_food_item_id,
                    'inventory_lot_id' => $lot->id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $unit,
                    'movement_type' => StockMovement::TYPE_RECEIPT,
                    'movement_date' => $receipt->receipt_date,
                    'quantity_in_kg' => $acceptedKg,
                    'quantity_out_kg' => 0,
                    'quantity_in' => $accepted,
                    'quantity_out' => 0,
                    'source_type' => StockReceipt::class,
                    'source_id' => $receipt->id,
                    'reference_number' => $receipt->receipt_number,
                    'supplier_batch_number' => $item->supplier_batch_number,
                    'expired_date' => $item->expired_date,
                    'notes' => trim(implode(' | ', array_filter([
                        $item->quality_notes,
                        $accepted > 0 ? 'Jumlah diterima: '.number_format($accepted, 4, ',', '.').' '.$unit : null,
                    ]))),
                    'created_by' => auth()->id(),
                ]);

            }

            $receipt->forceFill([
                'status' => StockReceipt::STATUS_RECEIVED,
                'received_by' => auth()->id(),
                'received_at' => now(),
            ])->save();

            return $receipt->refresh();
        });
    }
}
