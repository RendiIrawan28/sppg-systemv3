<?php

namespace Tests\Feature;

use App\Enums\DistributionRunState;
use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use App\Enums\UserRole;
use App\Livewire\V3\Portioning\Index as PortioningIndex;
use App\Models\DistributionRun;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\PortioningWorkflow;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortioningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_portioning_can_start_before_processing_is_completed(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->portioningSession($unit, $staff);

        app(PortioningWorkflow::class)->start($session, $staff);

        $this->assertSame(PortioningSessionState::InProgress, $session->fresh()->state);
        $this->assertNotNull($session->fresh()->started_at);
    }

    public function test_direct_warehouse_withdrawal_becomes_portioning_supply_before_stock_verification(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = User::factory()->create();
        $session = $this->portioningSession($unit, $staff);
        $lot = $this->lot($unit, $ingredient, 20);
        $service = app(WarehouseWithdrawalService::class);

        $withdrawal = $service->createAndSubmit(
            $unit->id,
            'pemorsian',
            'portioning_session',
            $session->id,
            'Perlengkapan Pemorsian',
            null,
            [['inventory_lot_id' => $lot->id, 'quantity' => 5, 'photo_path' => 'test/portioning-supply.jpg']],
            $staff,
        );
        $this->assertDatabaseHas('portioning_supplies', [
            'portioning_session_id' => $session->id,
            'source_type' => 'warehouse_withdrawal',
            'source_id' => $withdrawal->id,
            'inventory_lot_id' => $lot->id,
            'quantity' => 5,
            'unit_name' => 'pak',
        ]);
        $this->assertSame('20.0000', $lot->fresh()->balance_quantity);

        $item = $withdrawal->items()->firstOrFail();
        $service->verify($withdrawal, $warehouse, [$item->id => 4]);

        $this->assertDatabaseHas('portioning_supplies', [
            'portioning_session_id' => $session->id,
            'source_type' => 'warehouse_withdrawal',
            'source_id' => $withdrawal->id,
            'quantity' => 4,
        ]);
        $this->assertSame('16.0000', $lot->fresh()->balance_quantity);
    }

    public function test_portioning_completion_requires_planned_routes_and_active_portion_photos(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);

        app(PortioningWorkflow::class)->complete($session, $staff);

        $this->assertSame(PortioningSessionState::Completed, $session->fresh()->state);
        $this->assertDatabaseHas('portioning_histories', [
            'portioning_session_id' => $session->id,
            'action' => 'completed',
        ]);
    }

    public function test_portioning_completion_keeps_distribution_destination_plan_unchanged(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $run = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id, 'portioning_session_id' => $session->id,
            'distribution_date' => today(), 'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned, 'status' => OperationalReportStatus::Draft,
            'created_by' => $staff->id,
        ]);
        $stop = $run->stops()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah Uji', 'destination_type' => 'school',
            'sequence_order' => 1, 'small_portions' => 50, 'large_portions' => 50, 'containers_sent' => 100,
        ]);
        $run->recalculateTotals();

        app(PortioningWorkflow::class)->complete($session->fresh(), $staff);

        $run->refresh();
        $stop->refresh();
        $this->assertSame(50, $run->planned_small_portions);
        $this->assertSame(50, $run->planned_large_portions);
        $this->assertSame(50, $stop->small_portions);
        $this->assertSame(50, $stop->large_portions);
        $this->assertSame(100, $stop->containers_sent);
    }

    public function test_each_route_requires_one_documentation_photo(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->routeRecords()->firstOrFail()->update(['photo_path' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Jumlah, dokumentasi, dan waktu setiap rute wajib lengkap.');

        app(PortioningWorkflow::class)->complete($session, $staff);
    }

    public function test_portion_quantity_may_differ_from_plan_when_route_has_notes(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->routeRecords()->firstOrFail()->update([
            'large_portions' => 59,
        ]);
        $session->update(['notes' => 'Satu penerima batal hadir.']);
        $session->recalculateTotals();

        app(PortioningWorkflow::class)->complete($session, $staff);

        $this->assertSame(PortioningSessionState::Completed, $session->fresh()->state);
        $this->assertSame(99, $session->fresh()->actual_total);
    }

    public function test_portion_quantity_variance_requires_route_notes(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->routeRecords()->firstOrFail()->update([
            'large_portions' => 59,
        ]);
        $session->update(['notes' => null]);
        $session->recalculateTotals();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Catatan wajib diisi karena total Pemorsian belum sesuai target harian.');

        app(PortioningWorkflow::class)->complete($session, $staff);
    }

    public function test_portioning_completion_requires_leftover_declaration(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->update(['leftover_mode' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pilih apakah terdapat sisa makanan.');

        app(PortioningWorkflow::class)->complete($session, $staff);
    }

    public function test_portioning_form_completes_route_and_stores_overall_leftover(): void
    {
        Storage::fake('public');
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->portioningSession($unit, $staff);
        $session->update(['state' => PortioningSessionState::InProgress]);

        Livewire::actingAs($staff)
            ->test(PortioningIndex::class)
            ->call('select', $session->id)
            ->assertSee('Tambah hasil rute')
            ->assertSee('Sisa makanan setelah Pemorsian')
            ->assertDontSee('Sampel berat')
            ->assertDontSee('Checklist higiene')
            ->assertDontSee('Pemantauan suhu')
            ->assertDontSee('Serahkan ke Distribusi')
            ->set('routeForm.route_name', 'Rute 1')
            ->set('routeForm.small_portions', 39)
            ->set('routeForm.large_portions', 60)
            ->set('routeForm.notes', 'Pemorsian rute pertama.')
            ->set('routePhoto', UploadedFile::fake()->image('rute-1.jpg'))
            ->assertSee('rute-1.jpg')
            ->call('saveRoute')
            ->set('notes', 'Satu porsi kecil tidak digunakan.')
            ->call('setLeftoverMode', 'present')
            ->set('leftovers.0.food_type', 'Nasi')
            ->set('leftovers.0.quantity', '1.25')
            ->set('leftovers.0.unit_name', 'kg')
            ->set('leftovers.0.notes', 'Sisa nasi setelah seluruh rute selesai.')
            ->set('leftoverPhotos.0', UploadedFile::fake()->image('sisa-nasi.jpg'))
            ->assertSee('sisa-nasi.jpg')
            ->call('saveFinalData')
            ->assertHasNoErrors();

        $session->refresh();
        $this->assertSame(39, $session->actual_small_portions);
        $this->assertSame(60, $session->actual_large_portions);
        $this->assertDatabaseHas('portioning_route_records', [
            'portioning_session_id' => $session->id,
            'route_name' => 'Rute 1',
            'small_portions' => 39,
            'large_portions' => 60,
            'photo_original_name' => 'rute-1.jpg',
        ]);
        $this->assertDatabaseHas('portioning_leftover_records', [
            'portioning_session_id' => $session->id,
            'quantity' => 1.25,
            'unit_name' => 'kg',
            'photo_original_name' => 'sisa-nasi.jpg',
        ]);
        Storage::disk('public')->assertExists($session->routeRecords()->firstOrFail()->photo_path);
        Storage::disk('public')->assertExists($session->leftoverRecords()->firstOrFail()->photo_path);
    }

    public function test_manual_routes_are_saved_one_by_one_and_aggregated_against_daily_target(): void
    {
        Storage::fake('public');
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->portioningSession($unit, $staff);
        $session->update(['state' => PortioningSessionState::InProgress]);

        Livewire::actingAs($staff)
            ->test(PortioningIndex::class)
            ->call('select', $session->id)
            ->set('routeForm.route_name', 'Rute 1')
            ->set('routeForm.small_portions', 15)
            ->set('routeForm.large_portions', 25)
            ->set('routePhoto', UploadedFile::fake()->image('rute-1.jpg'))
            ->call('saveRoute')
            ->assertHasNoErrors()
            ->set('routeForm.route_name', 'Rute 2')
            ->set('routeForm.small_portions', 25)
            ->set('routeForm.large_portions', 35)
            ->set('routePhoto', UploadedFile::fake()->image('rute-2.jpg'))
            ->call('saveRoute')
            ->assertHasNoErrors();

        $session->refresh();
        $this->assertSame(40, $session->actual_small_portions);
        $this->assertSame(60, $session->actual_large_portions);
        $this->assertSame(100, $session->actual_total);
        $this->assertCount(2, $session->routeRecords);
        $this->assertDatabaseHas('portioning_route_records', [
            'portioning_session_id' => $session->id,
            'route_name' => 'Rute 1',
        ]);
        $this->assertDatabaseHas('portioning_route_records', [
            'portioning_session_id' => $session->id,
            'route_name' => 'Rute 2',
        ]);
    }

    public function test_portioning_report_uses_division_head_then_field_assistant_verification(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $head = $this->userWithPermission('portioning.approve');
        $head->assignRole(Role::findOrCreate(UserRole::KepalaDivisiPemorsian->value));
        $fieldAssistant = $this->userWithPermission('portioning.approve');
        $fieldAssistant->assignRole(Role::findOrCreate(UserRole::AsistenLapangan->value));
        $session = $this->readyInProgressSession($unit, $staff);
        $workflow = app(PortioningWorkflow::class);
        $workflow->complete($session->fresh(), $staff);
        $workflow->submit($session->fresh(), $staff);
        $workflow->verify($session->fresh(), $head, 'Jumlah dan dokumentasi sesuai.');

        $this->assertSame(OperationalReportStatus::DivisionApproved, $session->fresh()->status);
        $this->assertSame($head->id, $session->fresh()->division_approved_by);

        $workflow->verify($session->fresh(), $fieldAssistant, 'Diverifikasi Asisten Lapangan.');
        $this->assertSame(OperationalReportStatus::Verified, $session->fresh()->status);
        $this->assertSame($fieldAssistant->id, $session->fresh()->verified_by);
    }

    /** @return array{SppgUnit, Ingredient, User} */
    private function baseData(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-PORTION', 'name' => 'SPPG Pemorsian', 'slug' => 'sppg-pemorsian', 'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id, 'code' => 'SUP-PORTION', 'name' => 'Wadah Makanan', 'is_active' => true,
        ]);

        return [$unit, $ingredient, $this->userWithPermission('processing.update', 'portioning.view', 'portioning.update', 'portioning.submit')];
    }

    private function portioningSession(SppgUnit $unit, User $staff): PortioningSession
    {
        $session = PortioningSession::query()->create([
            'sppg_unit_id' => $unit->id, 'portioning_date' => today(),
            'menu_name_snapshot' => 'Menu Uji', 'state' => PortioningSessionState::Planned,
            'status' => OperationalReportStatus::Draft, 'created_by' => $staff->id,
        ]);
        $session->routeAllocations()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah Uji', 'destination_type' => 'school',
            'target_small_portions' => 40, 'target_large_portions' => 60,
            'sort_order' => 1,
        ]);
        $session->recalculateTotals();

        return $session;
    }

    private function readyInProgressSession(SppgUnit $unit, User $staff): PortioningSession
    {
        $session = $this->portioningSession($unit, $staff);
        $session->update([
            'state' => PortioningSessionState::InProgress,
            'leftover_mode' => 'none',
        ]);
        $route = $session->routeAllocations()->firstOrFail();
        $session->routeRecords()->create([
            'route_name' => $route->route_name,
            'small_portions' => 40,
            'large_portions' => 60,
            'photo_path' => 'test/route-1.jpg',
            'completed_at' => now(),
            'created_by' => $staff->id,
        ]);
        $session->recalculateTotals();

        return $session;
    }

    private function lot(SppgUnit $unit, Ingredient $ingredient, float $balance): InventoryLot
    {
        return InventoryLot::query()->create([
            'sppg_unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'unit_snapshot' => 'pak',
            'initial_quantity' => $balance, 'balance_quantity' => $balance, 'initial_quantity_kg' => 0,
            'balance_quantity_kg' => 0, 'lot_number' => 'LOT-SUPPLY', 'storage_type' => 'dry',
            'status' => InventoryLot::AVAILABLE,
        ]);
    }

    private function userWithPermission(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}
