<?php

namespace App\Support\V3;

use App\Models\AttendanceSession;
use App\Models\BeneficiaryPeriod;
use App\Models\CleaningSession;
use App\Models\ContainerCollectionRun;
use App\Models\DailyBeneficiaryConfirmation;
use App\Models\DistributionRun;
use App\Models\FieldDailyReport;
use App\Models\FieldDistributionPlan;
use App\Models\FieldIncident;
use App\Models\MenuCycle;
use App\Models\NutritionRequirementPlan;
use App\Models\OpeningStock;
use App\Models\PortioningSession;
use App\Models\PreparationOutput;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\ProcessingReturn;
use App\Models\ProcurementRequest;
use App\Models\SecurityShift;
use App\Models\StockAdjustment;
use App\Models\StockReceipt;
use App\Models\WarehouseWithdrawal;
use App\Models\WashingSession;
use App\Models\WasteHandoverReport;

final class TestDataCleanupRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'beneficiary-periods' => $this->item('Periode penerima', BeneficiaryPeriod::class, 'start_date', 'code', 'name'),
            'menu-cycles' => $this->item('Siklus/perencanaan menu', MenuCycle::class, 'start_date', 'code', 'name'),
            'nutrition-requirements' => $this->item('Kebutuhan bahan', NutritionRequirementPlan::class, 'requirement_date', 'plan_number', 'notes'),
            'procurements' => $this->item('Pengadaan bahan', ProcurementRequest::class, 'request_date', 'request_number', 'notes'),
            'opening-stocks' => $this->item('Input stok awal', OpeningStock::class, 'opening_date', 'opening_number', 'notes'),
            'stock-receipts' => $this->item('Penerimaan barang', StockReceipt::class, 'receipt_date', 'receipt_number', 'received_by_name'),
            'stock-adjustments' => $this->item('Penyesuaian stok', StockAdjustment::class, 'adjustment_date', 'adjustment_number', 'reason'),
            'warehouse-withdrawals' => $this->item('Pengambilan gudang', WarehouseWithdrawal::class, 'withdrawal_date', 'withdrawal_number', 'division_code'),
            'daily-confirmations' => $this->item('Konfirmasi penerima harian', DailyBeneficiaryConfirmation::class, 'service_date', 'id', 'destination_name_snapshot'),
            'field-plans' => $this->item('Rencana distribusi', FieldDistributionPlan::class, 'distribution_date', 'plan_number', 'general_notes'),
            'field-daily-reports' => $this->item('Laporan harian lapangan', FieldDailyReport::class, 'report_date', 'report_number', 'operational_summary'),
            'field-incidents' => $this->item('Insiden lapangan', FieldIncident::class, 'incident_date', 'uuid', 'title'),
            'preparation-sessions' => $this->item('Pekerjaan persiapan', PreparationSession::class, 'preparation_date', 'session_number', 'notes'),
            'preparation-outputs' => $this->item('Hasil persiapan', PreparationOutput::class, 'stored_at', 'id', 'output_name'),
            'preparation-returns' => $this->item('Retur persiapan', PreparationReturn::class, 'return_date', 'return_number', 'reason'),
            'processing-batches' => $this->item('Pekerjaan pengolahan', ProcessingBatch::class, 'production_date', 'batch_number', 'product_name'),
            'processing-returns' => $this->item('Retur pengolahan', ProcessingReturn::class, 'return_date', 'return_number', 'reason'),
            'portioning-sessions' => $this->item('Pekerjaan pemorsian', PortioningSession::class, 'portioning_date', 'session_number', 'menu_name_snapshot'),
            'distribution-runs' => $this->item('Perjalanan distribusi', DistributionRun::class, 'distribution_date', 'run_number', 'route_name'),
            'container-collections' => $this->item('Pengambilan ompreng', ContainerCollectionRun::class, 'collection_date', 'run_number', 'notes'),
            'washing-sessions' => $this->item('Pekerjaan pencucian', WashingSession::class, 'washing_date', 'session_number', 'menu_name_snapshot'),
            'cleaning-sessions' => $this->item('Pekerjaan kebersihan', CleaningSession::class, 'scheduled_date', 'session_number', 'notes'),
            'waste-handovers' => $this->item('Berita acara limbah', WasteHandoverReport::class, 'report_date', 'report_number', 'division_type'),
            'security-shifts' => $this->item('Shift dan laporan keamanan', SecurityShift::class, 'started_at', 'uuid', 'officer_name_snapshot'),
            'attendance-sessions' => $this->item('Presensi relawan', AttendanceSession::class, 'work_date', 'uuid', 'user_id'),
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $type): array
    {
        $definition = $this->definitions()[$type] ?? null;
        abort_unless($definition, 404);

        return $definition;
    }

    /** @return array<string, mixed> */
    private function item(string $label, string $model, string $date, string $number, string $title): array
    {
        return compact('label', 'model', 'date', 'number', 'title');
    }
}
