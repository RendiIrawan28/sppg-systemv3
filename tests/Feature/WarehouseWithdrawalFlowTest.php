<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\V3\Warehouse\Withdrawals\Index as WithdrawalIndex;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseWithdrawalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitted_preparation_withdrawal_creates_session_before_warehouse_verification(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $lot = $this->lot($unit, $ingredient, 'LOT-01', today()->addDays(2), 10);
        $service = app(WarehouseWithdrawalService::class);

        $withdrawal = $service->createAndSubmit(
            $unit->id,
            'persiapan',
            'field_plan',
            $plan->id,
            'Produksi 20 Juli',
            null,
            [[
                'inventory_lot_id' => $lot->id,
                'quantity' => 3,
                'pickup_temperature_celsius' => '',
                'photo_path' => 'test/lot-01.jpg',
            ]],
            $actor,
        );
        $this->assertNull($withdrawal->items()->firstOrFail()->pickup_temperature_celsius);
        $this->assertDatabaseHas('preparation_sessions', [
            'warehouse_withdrawal_id' => $withdrawal->id,
            'state' => 'planned',
        ]);
        $this->assertSame(1, PreparationSession::query()->count());
        $this->assertSame('10.0000', $lot->fresh()->balance_quantity);

        $service->verify($withdrawal, $verifier);

        $this->assertSame(WarehouseWithdrawal::VERIFIED, $withdrawal->fresh()->status);
        $this->assertSame('7.0000', $lot->fresh()->balance_quantity_kg);
        $this->assertSame('7.0000', $lot->fresh()->balance_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $lot->id,
            'movement_type' => StockMovement::TYPE_HANDOVER,
            'quantity_out_kg' => 3,
            'quantity_out' => 3,
        ]);
        $this->assertSame(1, PreparationSession::query()->count());
    }

    public function test_later_expiring_lot_is_rejected_while_an_earlier_lot_has_stock(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $this->lot($unit, $ingredient, 'LOT-FEFO', today()->addDay(), 5);
        $laterLot = $this->lot($unit, $ingredient, 'LOT-LATER', today()->addDays(5), 5);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Gunakan lot LOT-FEFO terlebih dahulu sesuai FEFO/FIFO.');

        app(WarehouseWithdrawalService::class)->createAndSubmit(
            $unit->id,
            'persiapan',
            'field_plan',
            $plan->id,
            'Produksi 20 Juli',
            null,
            [['inventory_lot_id' => $laterLot->id, 'quantity' => 1, 'photo_path' => 'test/lot-later.jpg']],
            $actor,
        );
    }

    public function test_pending_withdrawal_reserves_stock_without_reducing_official_balance(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $lot = $this->lot($unit, $ingredient, 'LOT-RESERVE', today()->addDays(2), 10);
        $service = app(WarehouseWithdrawalService::class);

        $service->createAndSubmit($unit->id, 'persiapan', 'field_plan', $plan->id, '', null, [
            ['inventory_lot_id' => $lot->id, 'quantity' => 7, 'photo_path' => 'test/reserve.jpg'],
        ], $actor);

        $this->assertSame('10.0000', $lot->fresh()->balance_quantity);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Saldo lot LOT-RESERVE tidak mencukupi.');

        $service->createAndSubmit($unit->id, 'persiapan', 'field_plan', $plan->id, '', null, [
            ['inventory_lot_id' => $lot->id, 'quantity' => 4, 'photo_path' => 'test/second.jpg'],
        ], $actor);
    }

    public function test_rejected_pending_withdrawal_removes_provisional_preparation_session_without_changing_stock(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $lot = $this->lot($unit, $ingredient, 'LOT-REJECT', today()->addDays(2), 10);
        $service = app(WarehouseWithdrawalService::class);

        $withdrawal = $service->createAndSubmit($unit->id, 'persiapan', 'field_plan', $plan->id, '', null, [
            ['inventory_lot_id' => $lot->id, 'quantity' => 3, 'photo_path' => 'test/reject.jpg'],
        ], $actor);

        $this->assertDatabaseHas('preparation_sessions', ['warehouse_withdrawal_id' => $withdrawal->id]);
        $service->reject($withdrawal, $verifier, 'Jenis barang tidak sesuai.');

        $this->assertDatabaseMissing('preparation_sessions', ['warehouse_withdrawal_id' => $withdrawal->id]);
        $this->assertDatabaseMissing('preparation_session_items', ['warehouse_withdrawal_item_id' => $withdrawal->items()->firstOrFail()->id]);
        $this->assertSame('10.0000', $lot->fresh()->balance_quantity);
        $this->assertSame(WarehouseWithdrawal::REJECTED, $withdrawal->fresh()->status);
    }

    public function test_warehouse_can_verify_a_different_actual_quantity_in_the_lots_original_unit(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $lot = $this->lot($unit, $ingredient, 'LOT-LITER', today()->addDays(3), 10, 'liter');
        $service = app(WarehouseWithdrawalService::class);
        $withdrawal = $service->createAndSubmit($unit->id, 'persiapan', 'field_plan', $plan->id, '', null, [
            ['inventory_lot_id' => $lot->id, 'quantity' => 5, 'photo_path' => 'test/liter.jpg'],
        ], $actor);

        $item = $withdrawal->items()->firstOrFail();
        $sessionItem = PreparationSession::query()
            ->where('warehouse_withdrawal_id', $withdrawal->id)
            ->firstOrFail()
            ->items()
            ->firstOrFail();
        $this->assertSame('5.0000', $sessionItem->received_quantity);

        $service->verify($withdrawal, $verifier, [$item->id => 4.5]);

        $this->assertSame('5.5000', $lot->fresh()->balance_quantity);
        $this->assertSame('4.5000', $item->fresh()->actual_quantity);
        $this->assertSame('4.5000', $sessionItem->fresh()->received_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $lot->id,
            'unit_snapshot' => 'liter',
            'quantity_out' => 4.5,
            'quantity_out_kg' => 0,
        ]);
    }

    public function test_cold_storage_pickup_requires_a_temperature_and_photo(): void
    {
        [$unit, $ingredient, $actor, $verifier, $plan] = $this->baseData();
        $lot = $this->lot($unit, $ingredient, 'LOT-FROZEN', today()->addDays(3), 10, 'kg', 'freezer');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Suhu saat pengambilan lot LOT-FROZEN wajib dicatat.');

        app(WarehouseWithdrawalService::class)->createAndSubmit($unit->id, 'persiapan', 'field_plan', $plan->id, '', null, [
            ['inventory_lot_id' => $lot->id, 'quantity' => 2, 'photo_path' => 'test/frozen.jpg'],
        ], $actor);
    }

    public function test_processing_withdrawal_uses_manual_plan_selection_and_creates_only_the_selected_batch(): void
    {
        Storage::fake('public');
        [$unit, $ingredient, , , $plan] = $this->baseData();
        $plan->update([
            'menu_name_snapshot' => 'Nasi dan Ayam',
            'planned_total_portions' => 100,
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(Role::findOrCreate(UserRole::PetugasPengolahan->value));
        $lot = $this->lot($unit, $ingredient, 'LOT-MANUAL', today()->addDays(3), 10);

        $component = Livewire::actingAs($actor)->test(WithdrawalIndex::class);
        $component->assertSee('Rencana/batch aktif');
        $component->assertSee('Catatan pengambilan bahan');
        $component->assertSee('buat batch Pengolahan');
        $withdrawalForm = app(WithdrawalIndex::class);
        $withdrawalForm->mount();
        $withdrawalForm->referenceId = "plan:{$plan->id}";
        $withdrawalForm->notes = 'Bahan diambil untuk produksi pagi.';
        $withdrawalForm->rows = [[
            'inventory_lot_id' => (string) $lot->id,
            'quantity' => '2',
            'pickup_temperature_celsius' => '',
            'photo' => UploadedFile::fake()->image('barang.jpg'),
        ]];
        $withdrawalForm->submit(app(WarehouseWithdrawalService::class));

        $activeBatch = ProcessingBatch::query()
            ->where('field_distribution_plan_id', $plan->id)
            ->firstOrFail();
        $this->assertDatabaseHas('warehouse_withdrawals', [
            'division_code' => 'pengolahan',
            'reference_type' => 'processing_batch',
            'reference_id' => $activeBatch->id,
            'reference_number_snapshot' => $activeBatch->batch_number,
            'purpose_reference' => $activeBatch->batch_number,
            'notes' => 'Bahan diambil untuk produksi pagi.',
        ]);
        $this->assertSame($activeBatch->id, $plan->fresh()->processing_batch_id);
        $this->assertDatabaseCount('portioning_sessions', 0);
        $this->assertDatabaseCount('distribution_runs', 0);
        $this->assertDatabaseHas('processing_material_usages', [
            'processing_batch_id' => $activeBatch->id,
            'inventory_lot_id' => $lot->id,
            'quantity' => 2,
        ]);
    }

    public function test_portioning_withdrawal_can_create_its_session_from_an_active_plan(): void
    {
        Storage::fake('public');
        [$unit, $ingredient, , , $plan] = $this->baseData();
        $plan->update(['menu_name_snapshot' => 'Nasi dan Ayam']);
        FieldDistributionPlanDestination::query()->create([
            'field_distribution_plan_id' => $plan->id,
            'destination_type' => 'school',
            'destination_id' => 101,
            'destination_name_snapshot' => 'SD Negeri Harapan',
            'route_name' => 'Rute 1',
            'sequence_order' => 1,
            'registered_beneficiaries' => 100,
            'confirmed_beneficiaries' => 100,
            'small_portions' => 40,
            'large_portions' => 60,
            'confirmation_status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(Role::findOrCreate(UserRole::PetugasPemorsian->value));
        $lot = $this->lot($unit, $ingredient, 'LOT-PORTIONING', today()->addDays(3), 10);

        Livewire::actingAs($actor)
            ->test(WithdrawalIndex::class)
            ->assertSee('buat sesi Pemorsian');

        $withdrawalForm = app(WithdrawalIndex::class);
        $withdrawalForm->mount();
        $withdrawalForm->referenceId = "plan:{$plan->id}";
        $withdrawalForm->notes = 'Kemasan untuk pemorsian.';
        $withdrawalForm->rows = [[
            'inventory_lot_id' => (string) $lot->id,
            'quantity' => '2',
            'pickup_temperature_celsius' => '',
            'photo' => UploadedFile::fake()->image('kemasan.jpg'),
        ]];
        $withdrawalForm->submit(app(WarehouseWithdrawalService::class));

        $session = PortioningSession::query()
            ->where('field_distribution_plan_id', $plan->id)
            ->firstOrFail();
        $batch = ProcessingBatch::query()
            ->where('field_distribution_plan_id', $plan->id)
            ->firstOrFail();

        $this->assertSame($batch->id, $session->processing_batch_id);
        $this->assertSame($session->id, $plan->fresh()->portioning_session_id);
        $this->assertDatabaseHas('portioning_route_allocations', [
            'portioning_session_id' => $session->id,
            'route_name' => 'Rute 1',
            'target_small_portions' => 40,
            'target_large_portions' => 60,
        ]);
        $this->assertDatabaseHas('warehouse_withdrawals', [
            'division_code' => 'pemorsian',
            'reference_type' => 'portioning_session',
            'reference_id' => $session->id,
            'notes' => 'Kemasan untuk pemorsian.',
        ]);
        $this->assertDatabaseHas('portioning_supplies', [
            'portioning_session_id' => $session->id,
            'inventory_lot_id' => $lot->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseCount('distribution_runs', 0);
    }

    /** @return array{SppgUnit, Ingredient, User, User, FieldDistributionPlan} */
    private function baseData(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-TEST',
            'name' => 'SPPG Test',
            'slug' => 'sppg-test',
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'BHN-001',
            'name' => 'Beras',
            'is_active' => true,
        ]);

        $plan = FieldDistributionPlan::query()->create([
            'sppg_unit_id' => $unit->id,
            'production_date' => today(),
            'distribution_date' => today(),
            'status' => 'activated',
        ]);

        return [$unit, $ingredient, User::factory()->create(), User::factory()->create(), $plan];
    }

    private function lot(
        SppgUnit $unit,
        Ingredient $ingredient,
        string $number,
        mixed $expiryDate,
        float $balance,
        string $unitName = 'kg',
        string $storageType = 'dry',
    ): InventoryLot {
        return InventoryLot::query()->create([
            'sppg_unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'unit_snapshot' => $unitName,
            'initial_quantity' => $balance,
            'balance_quantity' => $balance,
            'lot_number' => $number,
            'expired_date' => $expiryDate,
            'location_name' => 'Gudang Utama',
            'storage_type' => $storageType,
            'status' => InventoryLot::AVAILABLE,
            'initial_quantity_kg' => $unitName === 'kg' ? $balance : 0,
            'balance_quantity_kg' => $unitName === 'kg' ? $balance : 0,
        ]);
    }
}
