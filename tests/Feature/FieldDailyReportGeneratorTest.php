<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
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
}
