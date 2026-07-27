<?php

namespace Tests\Feature;

use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\OperationalReportStatus;
use App\Models\DistributionRun;
use App\Models\PreparationSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\FieldDailyReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDailyReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_current_preparation_sessions(): void
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-REPORT',
            'name' => 'SPPG Laporan',
            'slug' => 'sppg-laporan',
            'is_active' => true,
        ]);
        $actor = User::factory()->create();
        $withdrawal = WarehouseWithdrawal::query()->create([
            'sppg_unit_id' => $unit->id,
            'withdrawal_date' => today(),
            'division_code' => 'preparation',
            'purpose_reference' => 'Simulasi laporan harian',
            'status' => WarehouseWithdrawal::VERIFIED,
            'taken_by' => $actor->id,
        ]);
        PreparationSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'warehouse_withdrawal_id' => $withdrawal->id,
            'session_number' => 'PREP/REPORT/0001',
            'preparation_date' => today(),
            'state' => 'in_progress',
            'status' => OperationalReportStatus::Draft,
            'petugas_id' => $actor->id,
        ]);
        $report = app(FieldDailyReportGenerator::class)
            ->generate($unit->id, today()->toDateString(), $actor);

        $preparation = $report->divisions->firstWhere('division_code', 'preparation');
        $this->assertNotNull($preparation);
        $this->assertSame(1, $preparation->total_records);
        $this->assertSame('in_progress', $preparation->completion_status);
        $this->assertFalse($report->incidents->contains(
            fn ($incident): bool => $incident->division_code === 'preparation',
        ));
    }

    public function test_report_reads_container_reconciliation_from_distribution_route(): void
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-DST-REPORT',
            'name' => 'SPPG Distribusi Laporan',
            'slug' => 'sppg-distribusi-laporan',
            'is_active' => true,
        ]);
        $actor = User::factory()->create();

        $run = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id,
            'route_name' => 'Rute Utara',
            'distribution_date' => today(),
            'planned_small_portions' => 40,
            'planned_large_portions' => 60,
            'loaded_small_portions' => 40,
            'loaded_large_portions' => 60,
            'delivered_small_portions' => 40,
            'delivered_large_portions' => 55,
            'returned_small_portions' => 0,
            'returned_large_portions' => 5,
            'containers_returned' => 97,
            'containers_damaged' => 2,
            'containers_lost' => 1,
            'state' => DistributionRunState::Returned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $run->stops()->create([
            'route_name' => 'Rute Utara',
            'destination_name' => 'SD Contoh',
            'sequence_order' => 1,
            'small_portions' => 40,
            'large_portions' => 60,
            'delivered_small_portions' => 40,
            'delivered_large_portions' => 55,
            'returned_small_portions' => 0,
            'returned_large_portions' => 5,
            'containers_sent' => 100,
            'containers_returned' => 0,
            'containers_damaged' => 0,
            'containers_lost' => 0,
            'status' => DistributionStopStatus::Partial,
            'failure_reason' => 'Lima penerima tidak hadir.',
        ]);

        $report = app(FieldDailyReportGenerator::class)
            ->generate($unit->id, today()->toDateString(), $actor);

        $this->assertSame(100, $report->containers_sent);
        $this->assertSame(97, $report->containers_returned);
        $this->assertSame(2, $report->containers_damaged);
        $this->assertSame(1, $report->containers_lost);
    }

}
