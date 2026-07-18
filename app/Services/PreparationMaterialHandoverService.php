<?php

namespace App\Services;

use App\Models\PreparationMaterialHandover;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PreparationMaterialHandoverService
{
    public function createFromStockReceipt(StockReceipt $receipt): PreparationMaterialHandover
    {
        if ($receipt->status !== StockReceipt::STATUS_RECEIVED) {
            throw new InvalidArgumentException('Serah bahan hanya dapat dibuat dari penerimaan bahan yang sudah masuk stok.');
        }

        $receipt->loadMissing('items');

        if ($receipt->items->isEmpty()) {
            throw new InvalidArgumentException('Penerimaan bahan belum memiliki item.');
        }

        return DB::transaction(function () use ($receipt): PreparationMaterialHandover {
            $existing = PreparationMaterialHandover::query()
                ->where('sppg_unit_id', $receipt->sppg_unit_id)
                ->where('notes', 'like', "%[stock_receipt:{$receipt->id}]%")
                ->first();

            if ($existing) {
                return $existing->load('items');
            }

            $handover = PreparationMaterialHandover::query()->create([
                'sppg_unit_id' => $receipt->sppg_unit_id,
                'handover_date' => now()->toDateString(),
                'status' => PreparationMaterialHandover::STATUS_DRAFT,
                'warehouse_officer_name' => auth()->user()?->name,
                'notes' => trim("Dibuat dari penerimaan bahan {$receipt->receipt_number}. [stock_receipt:{$receipt->id}]"),
                'created_by' => auth()->id(),
            ]);

            foreach ($receipt->items as $item) {
                $quantity = (float) ($item->accepted_quantity ?: $item->accepted_quantity_kg ?: 0);
                $quantityKg = (float) ($item->accepted_quantity_kg ?: 0);

                if ($quantity <= 0 && $quantityKg <= 0) {
                    continue;
                }

                $unit = $item->unit_snapshot ?: 'unit';

                $handover->items()->create([
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $unit,
                    'requested_quantity' => $quantity,
                    'handed_over_quantity' => $quantity,
                    'requested_quantity_kg' => $quantityKg,
                    'handed_over_quantity_kg' => $quantityKg,
                    'received_quantity' => $quantity,
                    'good_quantity' => $quantity,
                    'moderate_quantity' => 0,
                    'damaged_quantity' => 0,
                    'prepared_quantity' => $quantity,
                    'waste_unit_snapshot' => $unit,
                    'waste_quantity' => 0,
                    'supplier_batch_number' => $item->supplier_batch_number,
                    'expired_date' => $item->expired_date,
                    'notes' => trim('Dari penerimaan '.$receipt->receipt_number.'. '.($item->quality_notes ?: '')),
                ]);
            }

            if (! $handover->items()->exists()) {
                throw new InvalidArgumentException('Tidak ada bahan diterima baik yang dapat diserahkan ke Persiapan.');
            }

            return $handover->refresh()->load('items');
        });
    }

    public function markHandedOver(PreparationMaterialHandover $handover): PreparationMaterialHandover
    {
        if (! $handover->isEditable()) {
            throw new InvalidArgumentException('Serah terima ini sudah dikunci.');
        }

        if (! $handover->items()->exists()) {
            throw new InvalidArgumentException('Serah terima belum memiliki bahan.');
        }

        return DB::transaction(function () use ($handover): PreparationMaterialHandover {
            $handover->load('items');

            foreach ($handover->items as $item) {
                $quantityKg = (float) $item->handed_over_quantity_kg;

                if ($quantityKg <= 0) {
                    continue;
                }

                $quantity = (float) ($item->handed_over_quantity ?: 0);
                $unit = $item->unit_snapshot ?: 'unit';

                StockMovement::query()->create([
                    'sppg_unit_id' => $handover->sppg_unit_id,
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $unit,
                    'movement_type' => StockMovement::TYPE_HANDOVER,
                    'movement_date' => $handover->handover_date,
                    'quantity_in_kg' => 0,
                    'quantity_out_kg' => $quantityKg,
                    'source_type' => PreparationMaterialHandover::class,
                    'source_id' => $handover->id,
                    'reference_number' => $handover->handover_number,
                    'supplier_batch_number' => $item->supplier_batch_number,
                    'expired_date' => $item->expired_date,
                    'notes' => trim(implode(' | ', array_filter([
                        $item->notes,
                        $quantity > 0 ? 'Jumlah serah: '.number_format($quantity, 4, ',', '.').' '.$unit : null,
                    ]))),
                    'created_by' => auth()->id(),
                ]);
            }

            $handover->forceFill([
                'status' => PreparationMaterialHandover::STATUS_HANDED_OVER,
                'handed_over_by' => auth()->id(),
                'handed_over_at' => now(),
            ])->save();

            return $handover->refresh();
        });
    }

    public function markReceived(PreparationMaterialHandover $handover): PreparationMaterialHandover
    {
        if ($handover->status !== PreparationMaterialHandover::STATUS_HANDED_OVER) {
            throw new InvalidArgumentException('Bahan hanya dapat diterima Persiapan setelah diserahkan Gudang.');
        }

        $handover->forceFill([
            'status' => PreparationMaterialHandover::STATUS_RECEIVED,
            'preparation_officer_name' => auth()->user()?->name,
            'received_by' => auth()->id(),
            'received_at' => now(),
        ])->save();

        return $handover->refresh();
    }

    public function markInspected(PreparationMaterialHandover $handover): PreparationMaterialHandover
    {
        if (! in_array($handover->status, [PreparationMaterialHandover::STATUS_RECEIVED, PreparationMaterialHandover::STATUS_INSPECTED], true)) {
            throw new InvalidArgumentException('Pemeriksaan kondisi hanya dapat disimpan setelah bahan diterima Persiapan.');
        }

        if (! $handover->items()->exists()) {
            throw new InvalidArgumentException('Belum ada bahan yang diperiksa.');
        }

        $handover->forceFill([
            'status' => PreparationMaterialHandover::STATUS_INSPECTED,
            'inspected_by' => auth()->id(),
            'inspected_at' => now(),
        ])->save();

        return $handover->refresh();
    }

    public function markPrepared(PreparationMaterialHandover $handover): PreparationMaterialHandover
    {
        if (! in_array($handover->status, [PreparationMaterialHandover::STATUS_INSPECTED, PreparationMaterialHandover::STATUS_PREPARED], true)) {
            throw new InvalidArgumentException('Bahan hanya dapat diproses Persiapan setelah pemeriksaan kondisi selesai.');
        }

        $handover->forceFill([
            'status' => PreparationMaterialHandover::STATUS_PREPARED,
            'prepared_by' => auth()->id(),
            'prepared_at' => now(),
        ])->save();

        return $handover->refresh();
    }

    public function markWasteRecorded(PreparationMaterialHandover $handover): PreparationMaterialHandover
    {
        if (! in_array($handover->status, [PreparationMaterialHandover::STATUS_PREPARED, PreparationMaterialHandover::STATUS_WASTE_RECORDED], true)) {
            throw new InvalidArgumentException('Limbah dicatat setelah proses pembersihan/pemotongan bahan selesai.');
        }

        $handover->forceFill([
            'status' => PreparationMaterialHandover::STATUS_WASTE_RECORDED,
            'waste_recorded_by' => auth()->id(),
            'waste_recorded_at' => now(),
        ])->save();

        return $handover->refresh();
    }
}
