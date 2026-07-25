<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Models\Ingredient;
use App\Models\PreparationSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreparationSessionReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_preparation_reports_can_be_downloaded_as_pdf(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::Verified);
        $user->givePermissionTo(Permission::findOrCreate('preparation.export'));

        foreach ([
            'preparation.sessions.calculation-pdf' => 'Berita-Acara-Perhitungan-PS-2026-0001.pdf',
            'preparation.sessions.waste-pdf' => 'Berita-Acara-Limbah-PS-2026-0001.pdf',
        ] as $route => $filename) {
            $response = $this->actingAs($user)->get(route($route, $session));

            $response->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader('content-disposition', 'attachment; filename='.$filename);

            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_preparation_reports_cannot_be_downloaded_before_head_approval(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::DivisionApproved);
        $user->givePermissionTo(Permission::findOrCreate('preparation.export'));

        $this->actingAs($user)
            ->get(route('preparation.sessions.calculation-pdf', $session))
            ->assertStatus(409);

        $this->actingAs($user)
            ->get(route('preparation.sessions.waste-pdf', $session))
            ->assertStatus(409);
    }

    public function test_preparation_reports_require_export_permission(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::Verified);

        $this->actingAs($user)
            ->get(route('preparation.sessions.calculation-pdf', $session))
            ->assertForbidden();
    }

    public function test_calculation_report_centers_quantity_and_marks_material_condition(): void
    {
        [, $session] = $this->reportFixture(OperationalReportStatus::Verified);
        $session->load(['sppgUnit', 'petugas', 'items.returns']);

        $html = view('reports.preparation-session-calculation-pdf', compact('session'))->render();

        $this->assertStringContainsString('<td class="center">100,000</td>', $html);
        $this->assertStringContainsString('<td class="checkmark">✓</td>', $html);
        $this->assertStringNotContainsString('97,500', $html);
        $this->assertStringNotContainsString('2,500', $html);
    }

    /** @return array{User, PreparationSession} */
    private function reportFixture(OperationalReportStatus $status): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-NOGOTIRTO',
            'name' => 'SPPG Nogotirto',
            'slug' => 'sppg-nogotirto',
            'address' => 'Nogotirto, Gamping, Sleman, Daerah Istimewa Yogyakarta',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Tim Persiapan',
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'BHN-BERAS',
            'name' => 'Beras',
            'is_active' => true,
        ]);
        $withdrawal = WarehouseWithdrawal::query()->create([
            'sppg_unit_id' => $unit->id,
            'withdrawal_date' => today(),
            'division_code' => 'persiapan',
            'status' => WarehouseWithdrawal::VERIFIED,
            'taken_by' => $user->id,
            'submitted_at' => now(),
            'verified_at' => now(),
        ]);
        $session = PreparationSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'warehouse_withdrawal_id' => $withdrawal->id,
            'session_number' => 'PS/2026/0001',
            'preparation_date' => today(),
            'state' => 'completed',
            'status' => $status,
            'petugas_id' => $user->id,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        $session->items()->create([
            'ingredient_id' => $ingredient->id,
            'ingredient_name_snapshot' => 'Beras',
            'unit_snapshot' => 'kg',
            'received_quantity' => 100,
            'processed_quantity' => 97.5,
            'waste_quantity' => 2.5,
            'received_weight_kg' => 100,
            'clean_weight_kg' => 97.5,
            'waste_weight_kg' => 2.5,
            'condition_status' => 'good',
            'notes' => 'Sisa sortir',
        ]);

        return [$user, $session];
    }
}
