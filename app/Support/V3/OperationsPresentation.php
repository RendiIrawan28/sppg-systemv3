<?php

namespace App\Support\V3;

use App\Models\PreparationMaterialHandover;
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
    public static function handoverStatuses(): array
    {
        return [
            PreparationMaterialHandover::STATUS_DRAFT => 'Draft',
            PreparationMaterialHandover::STATUS_HANDED_OVER => 'Diserahkan Gudang',
            PreparationMaterialHandover::STATUS_RECEIVED => 'Diterima Persiapan',
            PreparationMaterialHandover::STATUS_INSPECTED => 'Kondisi Diperiksa',
            PreparationMaterialHandover::STATUS_PREPARED => 'Bahan Siap Olah',
            PreparationMaterialHandover::STATUS_WASTE_RECORDED => 'Limbah Dicatat',
            PreparationMaterialHandover::STATUS_HANDED_OVER_TO_PROCESSING => 'Diserahkan ke Pengolahan',
            PreparationMaterialHandover::STATUS_COMPLETED => 'Selesai',
        ];
    }

    /** @return array<string, string> */
    public static function movementTypes(): array
    {
        return [
            StockMovement::TYPE_RECEIPT => 'Penerimaan',
            StockMovement::TYPE_HANDOVER => 'Serah ke Persiapan',
            StockMovement::TYPE_ADJUSTMENT => 'Penyesuaian',
        ];
    }

    public static function label(array $options, ?string $value): string
    {
        return $options[$value] ?? '-';
    }
}
