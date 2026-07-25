<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Models\Ingredient;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProcessingReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_processing_reports_can_be_downloaded_in_both_formats(): void
    {
        [$user, $batch] = $this->reportFixture(OperationalReportStatus::Verified);
        $user->givePermissionTo(Permission::findOrCreate('processing.export'));

        foreach ([
            'processing-batches.production-pdf' => 'PRD-SPPG-NOGOTIRTO-2026-0001-monitoring-produksi.pdf',
            'processing-batches.temperature-pdf' => 'PRD-SPPG-NOGOTIRTO-2026-0001-pemantauan-suhu.pdf',
        ] as $route => $filename) {
            $response = $this->actingAs($user)->get(route($route, $batch));

            $response->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader('content-disposition', 'attachment; filename='.$filename);
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_processing_reports_are_locked_until_head_sppg_approval(): void
    {
        [$user, $batch] = $this->reportFixture(OperationalReportStatus::DivisionApproved);
        $user->givePermissionTo(Permission::findOrCreate('processing.export'));

        $this->actingAs($user)
            ->get(route('processing-batches.production-pdf', $batch))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('processing-batches.temperature-pdf', $batch))
            ->assertForbidden();
    }

    /** @return array{User, ProcessingBatch} */
    private function reportFixture(OperationalReportStatus $status): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-NOGOTIRTO',
            'name' => 'SPPG Sleman Gamping Nogotirto',
            'slug' => 'sppg-nogotirto',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Petugas Pengolahan',
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'BHN-BERAS',
            'name' => 'Beras',
            'is_active' => true,
        ]);
        $batch = ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id,
            'batch_number' => 'PRD/SPPG-NOGOTIRTO/2026/0001',
            'production_date' => today(),
            'menu_name_snapshot' => 'Nasi Kuning',
            'product_name' => 'Nasi Kuning',
            'target_output_quantity' => 100,
            'target_output_unit' => 'porsi',
            'actual_output_quantity' => 100,
            'actual_output_unit' => 'porsi',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'state' => ProcessingBatchState::Completed,
            'status' => $status,
            'petugas_id' => $user->id,
            'petugas_name_snapshot' => $user->name,
            'created_by' => $user->id,
        ]);
        $batch->materialUsages()->create([
            'ingredient_id' => $ingredient->id,
            'material_name' => 'Beras',
            'quantity' => 10,
            'unit_name' => 'kg',
        ]);
        $batch->temperatureLogs()->create([
            'checked_at' => now(),
            'checkpoint' => ProcessingTemperatureCheckpoint::Final,
            'product_name' => 'Nasi Kuning',
            'temperature_celsius' => 72,
            'measured_by' => $user->id,
            'measured_name_snapshot' => $user->name,
        ]);

        return [$user, $batch];
    }
}
