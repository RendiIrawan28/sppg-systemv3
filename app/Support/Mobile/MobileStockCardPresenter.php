<?php

namespace App\Support\Mobile;

use App\Models\InventoryLot;
use App\Services\WarehouseStockCardService;
use App\Support\V3\OperationsPresentation;

class MobileStockCardPresenter
{
    public function summary(object $card): array
    {
        return [
            'id' => $card->id, 'number' => $card->code, 'title' => $card->ingredient_name_snapshot,
            'date' => $card->last_movement_date ? substr($card->last_movement_date, 0, 10) : null,
            'subtitle' => $card->conversion_warning ?: $card->active_lot_count.' lot aktif · '.$card->unit_snapshot,
            'state' => null, 'state_label' => null, 'status' => null, 'status_label' => null,
            'is_history' => false, 'assignee' => null,
            'metrics' => [
                ['label' => 'Saldo', 'value' => $this->number($card->balance_quantity).' '.$card->unit_snapshot],
                ['label' => 'Lot aktif', 'value' => (string) $card->active_lot_count],
            ],
        ];
    }

    public function detail(object $card, int $unitId): array
    {
        $service = app(WarehouseStockCardService::class);
        $types = OperationsPresentation::movementTypes();
        $lots = $service->detailLots($unitId, $card->warehouse_id, $card->ingredient_id);

        return [
            ...$this->summary($card),
            'fields' => [
                $this->field('ingredient_id', 'Bahan', $card->ingredient_name_snapshot),
                $this->field('code', 'Kode bahan', $card->code),
                $this->field('unit_snapshot', 'Satuan stok', $card->unit_snapshot),
                $this->field('balance_quantity', 'Saldo total', $this->number($card->balance_quantity)),
                $this->field('active_lot_count', 'Lot aktif', (string) $card->active_lot_count),
            ],
            'sections' => [
                ['key' => 'stock_card_lots', 'title' => 'Daftar lot', 'items' => $lots->map(fn (InventoryLot $lot) => [
                    'id' => $lot->id, 'title' => 'Lot #'.$lot->id.' · '.($lot->lot_number ?: 'Tanpa nomor supplier'),
                    'fields' => [
                        $this->field('received_date', 'Tanggal terima', \Illuminate\Support\Carbon::parse($lot->receiptItem?->receipt?->receipt_date ?? $lot->received_date ?? $lot->created_at)->format('d M Y')),
                        $this->field('expired_date', 'Kedaluwarsa', $lot->expired_date?->format('d M Y') ?? '—'),
                        $this->field('location_name', 'Lokasi', $lot->location_name.' · '.$lot->storage_type),
                        $this->field('balance_quantity', 'Saldo lot', $this->number((float) $lot->balance_quantity).' '.$lot->unit_snapshot),
                        $this->field('status', 'Status', ['available' => 'Tersedia', 'depleted' => 'Habis', 'quarantine' => 'Karantina', 'rejected' => 'Ditolak'][$lot->status] ?? $lot->status),
                    ],
                ])->all()],
                ['key' => 'stock_card_movements', 'title' => 'Riwayat mutasi · '.$card->unit_snapshot, 'items' => $service->ledger($unitId, $card->warehouse_id, $card->ingredient_id)->map(fn ($movement) => [
                    'id' => $movement->id, 'title' => $movement->reference_number ?: 'Mutasi #'.$movement->id,
                    'fields' => [
                        $this->field('movement_date', 'Tanggal', $movement->movement_date?->format('d M Y') ?? '—'),
                        $this->field('movement_type', 'Jenis', $types[$movement->movement_type] ?? $movement->movement_type),
                        $this->field('quantity_in', 'Masuk', $this->number($movement->card_quantity_in)),
                        $this->field('quantity_out', 'Keluar', $this->number($movement->card_quantity_out)),
                        $this->field('running_balance', 'Saldo berjalan', $this->number($movement->running_balance)),
                        $this->field('lot', 'Lot', '#'.$movement->inventory_lot_id.' · '.$movement->supplier_batch_number),
                        $this->field('notes', 'Catatan', $movement->notes ?: '—'),
                    ],
                ])->all()],
            ],
            'form_fields' => [],
        ];
    }

    private function number(?float $value): string
    {
        return $value === null ? 'Periksa satuan' : number_format($value, 3, ',', '.');
    }

    private function field(string $key, string $label, string $value): array
    {
        return compact('key', 'label', 'value') + ['type' => 'text', 'file_url' => null];
    }
}
