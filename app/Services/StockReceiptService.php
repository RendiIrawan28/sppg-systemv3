<?php

namespace App\Services;

use App\Models\ProcurementRequest;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockReceiptService
{
    public function createFromProcurementRequest(ProcurementRequest $request): StockReceipt
    {
        if ($request->status !== ProcurementRequest::STATUS_ORDERED) {
            throw new InvalidArgumentException('Penerimaan bahan hanya dapat dibuat dari permintaan yang sudah dipesan Gudang.');
        }

        return DB::transaction(function () use ($request): StockReceipt {
            $existing = StockReceipt::query()
                ->where('procurement_request_id', $request->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $request->loadMissing('items');

            $receipt = StockReceipt::query()->create([
                'sppg_unit_id' => $request->sppg_unit_id,
                'procurement_request_id' => $request->id,
                'receipt_date' => now()->toDateString(),
                'status' => StockReceipt::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($request->items as $item) {
                $ordered = (float) ($item->approved_quantity ?: $item->requested_quantity ?: 0);
                $orderedKg = (float) ($item->approved_quantity_kg ?: $item->requested_quantity_kg ?: 0);

                $receipt->items()->create([
                    'procurement_request_item_id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
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

        return DB::transaction(function () use ($receipt): StockReceipt {
            $receipt->load('items');

            foreach ($receipt->items as $item) {
                $acceptedKg = (float) $item->accepted_quantity_kg;

                if ($acceptedKg <= 0) {
                    continue;
                }

                $accepted = (float) ($item->accepted_quantity ?: 0);
                $unit = $item->unit_snapshot ?: 'unit';

                StockMovement::query()->create([
                    'sppg_unit_id' => $receipt->sppg_unit_id,
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                    'unit_snapshot' => $unit,
                    'movement_type' => StockMovement::TYPE_RECEIPT,
                    'movement_date' => $receipt->receipt_date,
                    'quantity_in_kg' => $acceptedKg,
                    'quantity_out_kg' => 0,
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
