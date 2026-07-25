<?php

namespace Tests\Feature;

use App\Enums\DistributionRunState;
use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Enums\UserRole;
use App\Models\DistributionRun;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\PortioningWorkflow;
use App\Services\ProcessingWorkflow;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortioningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_processing_automatically_becomes_portioning_input(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->processingBatch($unit, $staff, ProcessingBatchState::InProgress);
        $session = $this->portioningSession($unit, $staff, $batch);
        $batch->materialUsages()->create([
            'ingredient_id' => $ingredient->id,
            'material_name' => $ingredient->name,
            'quantity' => 5,
            'unit_name' => 'kg',
            'condition_status' => 'good',
        ]);
        $batch->temperatureLogs()->create([
            'checked_at' => now(),
            'checkpoint' => ProcessingTemperatureCheckpoint::Final,
            'product_name' => 'Menu Matang',
            'temperature_celsius' => 70,
            'measured_by' => $staff->id,
        ]);
        $batch->documentations()->create([
            'documentation_type' => 'after',
            'caption' => 'Menu Matang',
            'photo_path' => 'test/processing-result.jpg',
            'captured_at' => now(),
        ]);

        app(ProcessingWorkflow::class)->complete($batch, $staff);

        $session->refresh();
        $this->assertSame('100.000', $session->received_output_quantity);
        $this->assertSame('porsi', $session->received_output_unit);
        $this->assertSame('70.00', $session->received_temperature_celsius);
        $this->assertNull($session->received_by);
        $this->assertSame(8, $session->checklistItems()->count());
    }

    public function test_portioning_cannot_start_before_receiving_processing_output(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->portioningSession($unit, $staff);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum menerima hasil produksi');

        app(PortioningWorkflow::class)->start($session, $staff);
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

    public function test_portioning_completion_requires_each_active_size_checklist_temperature_and_photos(): void
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

    public function test_handover_to_distribution_requires_temperature_recipient_and_photo(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->update(['state' => PortioningSessionState::Completed]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('foto serah-terima Distribusi wajib diisi');

        app(PortioningWorkflow::class)->handover($session->fresh(), $staff, [
            'small_portions' => 40,
            'large_portions' => 60,
            'received_by_name' => 'Petugas Distribusi',
        ]);
    }

    public function test_portioning_handover_updates_distribution_load_from_actual_routes(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->update(['state' => PortioningSessionState::Completed]);
        $session->temperatureLogs()->create([
            'checked_at' => now(), 'checkpoint' => 'before_handover', 'temperature_celsius' => 67,
            'minimum_temperature' => 60, 'measured_by' => $staff->id,
        ]);
        $run = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id, 'portioning_session_id' => $session->id,
            'distribution_date' => today(), 'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned, 'status' => OperationalReportStatus::Draft,
            'created_by' => $staff->id,
        ]);
        $stop = $run->stops()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah Uji', 'destination_type' => 'school',
            'sequence_order' => 1, 'small_portions' => 50, 'large_portions' => 50,
        ]);

        app(PortioningWorkflow::class)->handover($session->fresh(), $staff, [
            'small_portions' => 40, 'large_portions' => 60,
            'received_by_name' => 'Driver Distribusi',
            'photo_path' => 'test/portioning-distribution.jpg',
        ]);

        $run->refresh();
        $stop->refresh();
        $this->assertNotNull($run->portioning_handover_id);
        $this->assertSame('67.00', $run->departure_temperature_celsius);
        $this->assertSame(40, $stop->small_portions);
        $this->assertSame(60, $stop->large_portions);
        $this->assertSame(100, $stop->containers_sent);
    }

    public function test_each_active_portion_size_requires_its_own_weight_sample(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->weightSamples()->where('portion_size', 'large')->delete();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Setiap kategori porsi aktif wajib memiliki sampel berat.');

        app(PortioningWorkflow::class)->complete($session, $staff);
    }

    public function test_real_quantity_may_differ_when_field_variance_is_explained(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->readyInProgressSession($unit, $staff);
        $session->routeAllocations()->firstOrFail()->update(['actual_large_portions' => 59]);
        $session->update(['input_variance_notes' => 'Satu porsi jatuh dan dipisahkan sebagai deviasi proses.']);

        app(PortioningWorkflow::class)->complete($session, $staff);

        $this->assertSame(99, $session->fresh()->actual_total);
        $this->assertSame(PortioningSessionState::Completed, $session->fresh()->state);
    }

    public function test_portioning_report_uses_division_head_then_sppg_head_approval(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $head = $this->userWithPermission('portioning.approve');
        $head->assignRole(Role::findOrCreate(UserRole::KepalaDivisiPemorsian->value));
        $sppgHead = $this->userWithPermission('portioning.approve');
        $sppgHead->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));
        $session = $this->readyInProgressSession($unit, $staff);
        $session->update(['state' => PortioningSessionState::Completed]);
        $session->temperatureLogs()->create([
            'checked_at' => now(), 'checkpoint' => 'before_handover', 'temperature_celsius' => 68,
            'minimum_temperature' => 60, 'measured_by' => $staff->id,
        ]);
        $workflow = app(PortioningWorkflow::class);
        $workflow->handover($session->fresh(), $staff, [
            'small_portions' => 40,
            'large_portions' => 60,
            'received_by_name' => 'Petugas Distribusi',
            'photo_path' => 'test/portioning-to-distribution.jpg',
        ]);
        $workflow->submit($session->fresh(), $staff);
        $workflow->verify($session->fresh(), $head, 'Jumlah dan sampel sesuai.');

        $this->assertSame(OperationalReportStatus::DivisionApproved, $session->fresh()->status);
        $this->assertSame($head->id, $session->fresh()->division_approved_by);

        $workflow->verify($session->fresh(), $sppgHead, 'Disetujui Kepala SPPG.');
        $this->assertSame(OperationalReportStatus::Verified, $session->fresh()->status);
        $this->assertSame($sppgHead->id, $session->fresh()->verified_by);
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

        return [$unit, $ingredient, $this->userWithPermission('processing.update', 'portioning.update', 'portioning.submit')];
    }

    private function processingBatch(SppgUnit $unit, User $staff, ProcessingBatchState $state = ProcessingBatchState::Planned): ProcessingBatch
    {
        return ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id, 'production_date' => today(), 'menu_name_snapshot' => 'Menu Uji',
            'product_name' => 'Menu Matang', 'target_output_quantity' => 100, 'target_output_unit' => 'porsi',
            'actual_output_quantity' => 100, 'actual_output_unit' => 'porsi', 'state' => $state,
            'status' => OperationalReportStatus::Draft, 'created_by' => $staff->id,
        ]);
    }

    private function portioningSession(SppgUnit $unit, User $staff, ?ProcessingBatch $batch = null): PortioningSession
    {
        $session = PortioningSession::query()->create([
            'sppg_unit_id' => $unit->id, 'processing_batch_id' => $batch?->id, 'portioning_date' => today(),
            'menu_name_snapshot' => 'Menu Uji', 'state' => PortioningSessionState::Planned,
            'status' => OperationalReportStatus::Draft, 'created_by' => $staff->id,
        ]);
        $session->routeAllocations()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah Uji', 'destination_type' => 'school',
            'target_small_portions' => 40, 'target_large_portions' => 60,
            'actual_small_portions' => 0, 'actual_large_portions' => 0, 'sort_order' => 1,
        ]);

        return $session;
    }

    private function readyInProgressSession(SppgUnit $unit, User $staff): PortioningSession
    {
        $session = $this->portioningSession($unit, $staff);
        $session->update([
            'state' => PortioningSessionState::InProgress, 'received_output_quantity' => 100,
            'received_output_unit' => 'porsi', 'received_by' => $staff->id, 'received_at' => now(),
        ]);
        $route = $session->routeAllocations()->firstOrFail();
        $route->update(['actual_small_portions' => 40, 'actual_large_portions' => 60, 'portioned_at' => now()]);
        $categories = ['hygiene', 'sanitation', 'cross_contamination', 'portion_standard', 'special_diet', 'packaging', 'time_temperature', 'reconciliation'];
        foreach ($categories as $index => $category) {
            $session->checklistItems()->create([
                'category' => $category, 'item_name' => str($category)->headline(), 'is_mandatory' => true,
                'result' => 'pass', 'checked_by' => $staff->id, 'checked_at' => now(), 'sort_order' => $index + 1,
            ]);
        }
        foreach ([['small', 150], ['large', 250]] as $index => [$size, $target]) {
            $session->weightSamples()->create([
                'portion_size' => $size, 'component_name' => 'Menu lengkap', 'sample_number' => 1,
                'target_weight_grams' => $target, 'actual_weight_grams' => $target,
                'tolerance_grams' => 5, 'checked_at' => now(), 'checked_by' => $staff->id,
            ]);
        }
        $session->temperatureLogs()->create([
            'checked_at' => now(), 'checkpoint' => 'during_portioning', 'temperature_celsius' => 70,
            'minimum_temperature' => 60, 'measured_by' => $staff->id,
        ]);
        $session->documentations()->createMany([
            ['phase' => 'before', 'photo_path' => 'test/before.jpg', 'captured_at' => now()],
            ['phase' => 'after', 'photo_path' => 'test/after.jpg', 'captured_at' => now()],
        ]);

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
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}
