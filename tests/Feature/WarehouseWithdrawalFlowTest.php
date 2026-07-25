<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\UserRole;
use App\Livewire\V3\Warehouse\Withdrawals\Index as WithdrawalIndex;
use App\Models\FieldDistributionPlan;
use App\Models\Ingredient;
use App\Models\InventoryLot;
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

    public function test_withdrawal_form_hides_manual_reference_fields_and_uses_the_active_division_job(): void
    {
        Storage::fake('public');
        [$unit, $ingredient] = $this->baseData();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(Role::findOrCreate(UserRole::PetugasPengolahan->value));
        $lot = $this->lot($unit, $ingredient, 'LOT-AUTO', today()->addDays(3), 10);

        ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id,
            'production_date' => today(),
            'menu_name_snapshot' => 'Pekerjaan Terencana',
            'product_name' => 'Pekerjaan Terencana',
            'target_output_quantity' => 100,
            'target_output_unit' => 'porsi',
            'state' => ProcessingBatchState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $actor->id,
        ]);
        $activeBatch = ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id,
            'production_date' => today()->subDay(),
            'menu_name_snapshot' => 'Pekerjaan Berjalan',
            'product_name' => 'Pekerjaan Berjalan',
            'target_output_quantity' => 100,
            'target_output_unit' => 'porsi',
            'state' => ProcessingBatchState::InProgress,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $actor->id,
        ]);

        $component = Livewire::actingAs($actor)->test(WithdrawalIndex::class);
        $component->assertDontSee('Rencana/batch aktif');
        $component->assertDontSee('Keterangan tambahan');
        $component->assertDontSee('Catatan opsional');
        $withdrawalForm = app(WithdrawalIndex::class);
        $withdrawalForm->mount();
        $withdrawalForm->rows = [[
            'inventory_lot_id' => (string) $lot->id,
            'quantity' => '2',
            'pickup_temperature_celsius' => '',
            'photo' => UploadedFile::fake()->image('barang.jpg'),
        ]];
        $withdrawalForm->submit(app(WarehouseWithdrawalService::class));

        $this->assertDatabaseHas('warehouse_withdrawals', [
            'division_code' => 'pengolahan',
            'reference_type' => 'processing_batch',
            'reference_id' => $activeBatch->id,
            'reference_number_snapshot' => $activeBatch->batch_number,
            'purpose_reference' => $activeBatch->batch_number,
        ]);
        $this->assertDatabaseHas('processing_material_usages', [
            'processing_batch_id' => $activeBatch->id,
            'inventory_lot_id' => $lot->id,
            'quantity' => 2,
        ]);
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
