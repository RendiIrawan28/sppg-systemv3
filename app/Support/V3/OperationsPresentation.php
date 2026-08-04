<?php

namespace App\Support\V3;

use App\Models\ProcurementRequest;
use App\Models\StockMovement;
use App\Models\StockReceipt;

final class OperationsPresentation
{
    /** @return array<string, string> */
    public static function procurementStatuses(): array
    {
        return [
            ProcurementRequest::STATUS_DRAFT => 'Draft Ahli Gizi',
            ProcurementRequest::STATUS_SUBMITTED => 'Menunggu Supplier dan Harga',
            ProcurementRequest::STATUS_REVISION => 'Perlu Revisi',
            ProcurementRequest::STATUS_FINANCE_VERIFIED => 'Harga Diverifikasi Keuangan',
            ProcurementRequest::STATUS_APPROVED => 'Harga Final Kepala SPPG',
            ProcurementRequest::STATUS_ORDERED => 'Dipesan Staf Gudang',
        ];
    }

    /** @return array<string, string> */
    public static function receiptStatuses(): array
    {
        return [
            StockReceipt::STATUS_DRAFT => 'Draft Penerimaan',
            StockReceipt::STATUS_RECEIVED => 'Diterima Gudang',
        ];
    }

    /** @return array<string, string> */
    public static function movementTypes(): array
    {
        return [
            StockMovement::TYPE_RECEIPT => 'Penerimaan',
            StockMovement::TYPE_HANDOVER => 'Pengambilan Divisi',
            StockMovement::TYPE_ADJUSTMENT => 'Penyesuaian',
            StockMovement::TYPE_OPENING_BALANCE => 'Stok Awal',
        ];
    }

    public static function label(array $options, ?string $value): string
    {
        return $options[$value] ?? '-';
    }
}
