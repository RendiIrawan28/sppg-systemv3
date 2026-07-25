<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\PreparationReturnService;
use App\Services\PreparationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PreparationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_withdrawal_creates_preparation_session_with_its_materials(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $lot = InventoryLot::query()->create([
            'sppg_unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'unit_snapshot' => 'kg',
            'initial_quantity' => 5,
            'balance_quantity' => 5,
            'initial_quantity_kg' => 5,
            'balance_quantity_kg' => 5,
            'lot_number' => 'LOT-COLD',
            'storage_type' => 'freezer',
            'status' => InventoryLot::AVAILABLE,
        ]);
        $withdrawal = $this->withdrawal($unit, $staff);
        $withdrawal->items()->create([
            'ingredient_id' => $ingredient->id,
            'inventory_lot_id' => $lot->id,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit_snapshot' => 'kg',
            'requested_quantity' => 2,
            'actual_quantity' => 2,
            'taken_quantity_kg' => 2,
            'verified_quantity_kg' => 2,
            'condition_status' => 'good',
        ]);

        $session = app(PreparationSessionService::class)->createFromWithdrawal($withdrawal);

        $this->assertNotNull($session);
        $this->assertSame(1, $session->items()->count());
    }

    public function test_completion_requires_reconciled_items_checklist_and_before_after_documentation(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->workingSession($unit, $ingredient, $staff);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Foto hasil Persiapan wajib tersedia.');

        app(PreparationSessionService::class)->complete($session, $staff);
    }

    public function test_session_can_complete_and_submit_with_one_result_photo(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $session = $this->workingSession($unit, $ingredient, $staff);
        $session->resultDocumentation()->create([
            'photo_path' => 'test/result.jpg',
            'captured_at' => now(),
            'created_by' => $staff->id,
        ]);

        $service = app(PreparationSessionService::class);
        $service->complete($session, $staff);
        $service->submit($session->fresh(), $staff);

        $this->assertSame('completed', $session->fresh()->state);
        $this->assertSame(OperationalReportStatus::Submitted, $session->fresh()->status);
        $this->assertDatabaseHas('preparation_histories', [
            'preparation_session_id' => $session->id,
            'action' => 'submitted',
        ]);
    }

    public function test_report_approval_follows_staff_division_head_and_sppg_head_sequence(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $head = $this->userWithPermission('preparation.approve');
        $head->assignRole(Role::findOrCreate(UserRole::KepalaDivisiPersiapan->value));
        $sppgHead = $this->userWithPermission('preparation.approve');
        $sppgHead->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));
        $session = $this->workingSession($unit, $ingredient, $staff);
        $session->update(['state' => 'completed']);

        $service = app(PreparationSessionService::class);
        $service->submit($session, $staff);
        $this->assertSame(OperationalReportStatus::Submitted, $session->fresh()->status);

        $service->approve($session->fresh(), $head, 'Data Persiapan sesuai.');
        $this->assertSame(OperationalReportStatus::DivisionApproved, $session->fresh()->status);

        $service->approve($session->fresh(), $sppgHead, 'Disetujui untuk arsip operasional.');
        $this->assertSame(OperationalReportStatus::Verified, $session->fresh()->status);
        $this->assertDatabaseHas('preparation_histories', [
            'preparation_session_id' => $session->id,
            'action' => 'head_approved',
            'to_status' => OperationalReportStatus::Verified->value,
        ]);
    }

    public function test_preparation_return_only_restores_source_lot_after_warehouse_verification(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = $this->userWithPermission('stock.approve');
        $lot = $this->inventoryLot($unit, $ingredient, 5);
        $session = $this->workingSession($unit, $ingredient, $staff);
        $item = $session->items()->firstOrFail();
        $item->update(['inventory_lot_id' => $lot->id]);

        $service = app(PreparationReturnService::class);
        $return = $service->submit($session, $item->fresh(), 1, 'good', 'Tidak terpakai dan kemasan masih utuh.', null, $staff);

        $this->assertSame('5.0000', $lot->fresh()->balance_quantity);
        $this->assertDatabaseMissing('stock_movements', [
            'source_type' => PreparationReturn::class,
            'source_id' => $return->id,
        ]);

        $service->verify($return, 0.75, 'available', 'Kemasan utuh, jumlah aktual diperiksa.', $warehouse);

        $this->assertSame('5.7500', $lot->fresh()->balance_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $lot->id,
            'movement_type' => StockMovement::TYPE_RETURN_FROM_PREPARATION,
            'quantity_in' => 0.75,
        ]);
    }

    public function test_damaged_return_is_separated_as_quarantine_and_not_added_to_available_source_lot(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = $this->userWithPermission('stock.approve');
        $lot = $this->inventoryLot($unit, $ingredient, 5);
        $session = $this->workingSession($unit, $ingredient, $staff);
        $item = $session->items()->firstOrFail();
        $item->update(['inventory_lot_id' => $lot->id]);

        $service = app(PreparationReturnService::class);
        $return = $service->submit($session, $item->fresh(), 1, 'damaged', 'Kemasan robek saat Persiapan.', 'test/damaged.jpg', $staff);
        $return = $service->verify($return, 1, 'quarantine', 'Pisahkan di area retur.', $warehouse);

        $this->assertSame('5.0000', $lot->fresh()->balance_quantity);
        $this->assertNotSame($lot->id, $return->destination_inventory_lot_id);
        $this->assertDatabaseHas('inventory_lots', [
            'id' => $return->destination_inventory_lot_id,
            'balance_quantity' => 1,
            'status' => InventoryLot::QUARANTINE,
            'location_name' => 'Area Retur Gudang',
        ]);
    }

    public function test_verified_return_is_included_in_preparation_reconciliation(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = $this->userWithPermission('stock.approve');
        $lot = $this->inventoryLot($unit, $ingredient, 5);
        $session = $this->workingSession($unit, $ingredient, $staff);
        $item = $session->items()->firstOrFail();
        $item->update([
            'inventory_lot_id' => $lot->id,
            'processed_quantity' => 4,
            'waste_quantity' => 0,
            'clean_weight_kg' => 4,
            'waste_weight_kg' => 0,
        ]);
        $session->resultDocumentation()->create([
            'photo_path' => 'test/result.jpg',
            'captured_at' => now(),
        ]);

        $returnService = app(PreparationReturnService::class);
        $return = $returnService->submit($session, $item->fresh(), 1, 'good', 'Tidak digunakan.', 'test/return.jpg', $staff);
        $returnService->verify($return, 1, 'available', null, $warehouse);
        app(PreparationSessionService::class)->complete($session->fresh(), $staff);

        $this->assertSame('completed', $session->fresh()->state);
    }

    /** @return array{SppgUnit, Ingredient, User} */
    private function baseData(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-PREP',
            'name' => 'SPPG Persiapan',
            'slug' => 'sppg-persiapan',
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'BHN-PREP',
            'name' => 'Ayam',
            'is_active' => true,
        ]);

        return [$unit, $ingredient, $this->userWithPermission('preparation.update', 'preparation.submit')];
    }

    private function workingSession(SppgUnit $unit, Ingredient $ingredient, User $staff): PreparationSession
    {
        $session = PreparationSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'warehouse_withdrawal_id' => $this->withdrawal($unit, $staff)->id,
            'session_number' => 'PS/TEST/'.fake()->unique()->numerify('####'),
            'preparation_date' => today(),
            'state' => 'in_progress',
            'status' => OperationalReportStatus::Draft,
            'petugas_id' => $staff->id,
            'started_at' => now(),
        ]);
        $session->items()->create([
            'ingredient_id' => $ingredient->id,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit_snapshot' => 'kg',
            'received_quantity' => 5,
            'processed_quantity' => 4.5,
            'waste_quantity' => 0.5,
            'condition_status' => 'good',
            'received_weight_kg' => 5,
            'clean_weight_kg' => 4.5,
            'waste_weight_kg' => 0.5,
        ]);

        return $session;
    }

    private function withdrawal(SppgUnit $unit, User $staff): WarehouseWithdrawal
    {
        return WarehouseWithdrawal::query()->create([
            'sppg_unit_id' => $unit->id,
            'withdrawal_date' => today(),
            'division_code' => 'persiapan',
            'status' => WarehouseWithdrawal::VERIFIED,
            'taken_by' => $staff->id,
            'submitted_at' => now(),
            'verified_at' => now(),
        ]);
    }

    private function inventoryLot(SppgUnit $unit, Ingredient $ingredient, float $balance): InventoryLot
    {
        return InventoryLot::query()->create([
            'sppg_unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'unit_snapshot' => 'kg',
            'initial_quantity' => $balance,
            'balance_quantity' => $balance,
            'initial_quantity_kg' => $balance,
            'balance_quantity_kg' => $balance,
            'lot_number' => 'LOT-RETURN-'.fake()->unique()->numerify('####'),
            'storage_type' => 'dry',
            'location_name' => 'Gudang Utama',
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
