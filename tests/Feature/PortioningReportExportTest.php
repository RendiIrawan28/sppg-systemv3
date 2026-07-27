<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use App\Enums\UserRole;
use App\Models\PortioningSession;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortioningReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_portioning_report_can_be_downloaded_from_packaging_form_template(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::Verified);
        $user->givePermissionTo(Permission::findOrCreate('portioning.export'));

        $response = $this->actingAs($user)
            ->get(route('portioning-sessions.pdf', $session));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader(
                'content-disposition',
                'attachment; filename=POR-SPPG-NOGOTIRTO-2026-0001-form-pengawasan-pengemasan.pdf',
            );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_portioning_report_is_locked_until_field_assistant_verification(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::DivisionApproved);
        $user->givePermissionTo(Permission::findOrCreate('portioning.export'));

        $this->actingAs($user)
            ->get(route('portioning-sessions.pdf', $session))
            ->assertForbidden();
    }

    public function test_verified_report_cannot_be_exported_when_verifier_is_not_field_assistant(): void
    {
        [$user, $session] = $this->reportFixture(OperationalReportStatus::Verified);
        $user->givePermissionTo(Permission::findOrCreate('portioning.export'));
        $head = User::factory()->create(['is_active' => true]);
        $head->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));
        $session->update(['verified_by' => $head->id]);

        $this->actingAs($user)
            ->get(route('portioning-sessions.pdf', $session))
            ->assertForbidden();
    }

    public function test_portioning_report_matches_packaging_supervision_fields_and_signatures(): void
    {
        [, $session] = $this->reportFixture(OperationalReportStatus::Verified);
        $session->load([
            'sppgUnit',
            'routeAllocations',
            'routeRecords',
            'leftoverRecords',
            'divisionApprover',
            'verifier',
        ]);

        $html = view('reports.portioning-session-pdf', [
            'session' => $session,
        ])->render();

        $this->assertStringContainsString('FORM PENGAWASAN PENGEMASAN', $html);
        $this->assertStringContainsString('FR/PN/01', $html);
        $this->assertStringContainsString('Qty Ompreng', $html);
        $this->assertStringContainsString('Sisa Makanan di Pemorsian', $html);
        $this->assertStringContainsString('Koordinator Pemorsian', $html);
        $this->assertStringContainsString('Kepala Pemorsian', $html);
        $this->assertStringContainsString('Asisten Lapangan Uji', $html);
        $this->assertStringContainsString('Asisten Lapangan', $html);
        $this->assertStringNotContainsString('Kepala SPPG', $html);
        $this->assertStringContainsString('Sisa nasi', $html);
    }

    /** @return array{User, PortioningSession} */
    private function reportFixture(OperationalReportStatus $status): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-NOGOTIRTO',
            'name' => 'SPPG Sleman Gamping Nogotirto',
            'slug' => 'sppg-nogotirto',
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'name' => 'Petugas Pemorsian',
            'is_active' => true,
        ]);
        $divisionHead = User::factory()->create([
            'name' => 'Kepala Pemorsian',
            'is_active' => true,
        ]);
        $fieldAssistant = User::factory()->create([
            'name' => 'Asisten Lapangan Uji',
            'is_active' => true,
        ]);
        $fieldAssistant->assignRole(Role::findOrCreate(UserRole::AsistenLapangan->value));
        $session = PortioningSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'session_number' => 'POR/SPPG-NOGOTIRTO/2026/0001',
            'portioning_date' => today(),
            'menu_name_snapshot' => 'Nasi Kuning',
            'target_small_portions' => 40,
            'target_large_portions' => 60,
            'actual_small_portions' => 40,
            'actual_large_portions' => 60,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'state' => PortioningSessionState::Completed,
            'status' => $status,
            'petugas_id' => $staff->id,
            'petugas_name_snapshot' => $staff->name,
            'leftover_mode' => 'present',
            'division_approved_by' => $divisionHead->id,
            'division_approved_at' => now(),
            'verified_by' => $fieldAssistant->id,
            'verified_at' => now(),
            'created_by' => $staff->id,
        ]);
        $session->routeAllocations()->createMany([
            [
                'route_name' => '1',
                'destination_name' => 'Sekolah A',
                'target_small_portions' => 25,
                'target_large_portions' => 35,
                'sort_order' => 1,
            ],
            [
                'route_name' => '1',
                'destination_name' => 'Sekolah B',
                'target_small_portions' => 15,
                'target_large_portions' => 25,
                'sort_order' => 2,
            ],
        ]);
        $session->routeRecords()->create([
            'route_name' => 'Rute 1',
            'small_portions' => 40,
            'large_portions' => 60,
            'photo_path' => 'test/rute-1.jpg',
            'notes' => 'Pemorsian selesai.',
            'completed_at' => now(),
            'created_by' => $staff->id,
        ]);
        $session->leftoverRecords()->create([
            'checked_at' => now(),
            'food_type' => 'Nasi Kuning',
            'quantity' => 1.25,
            'unit_name' => 'kg',
            'notes' => 'Sisa nasi',
            'created_by' => $staff->id,
        ]);

        return [$staff, $session];
    }
}
