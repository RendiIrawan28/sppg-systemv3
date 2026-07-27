<?php

namespace Tests\Feature;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Enums\UserRole;
use App\Livewire\V3\Processing\Index as ProcessingIndex;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\ProcessingBatch;
use App\Models\ProcessingReturn;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\ProcessingReturnService;
use App\Services\ProcessingWorkflow;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProcessingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_batch_cannot_start_before_receiving_material(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->batch($unit, $staff);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Batch belum menerima bahan');

        app(ProcessingWorkflow::class)->start($batch, $staff);
    }

    public function test_direct_warehouse_withdrawal_becomes_processing_input_before_stock_verification(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = User::factory()->create();
        $batch = $this->batch($unit, $staff);
        $lot = $this->lot($unit, $ingredient, 10);

        $service = app(WarehouseWithdrawalService::class);
        $withdrawal = $service->createAndSubmit(
            $unit->id,
            'pengolahan',
            'processing_batch',
            $batch->id,
            'Bahan tambahan Pengolahan',
            null,
            [['inventory_lot_id' => $lot->id, 'quantity' => 2, 'photo_path' => 'test/direct.jpg']],
            $staff,
        );
        $this->assertDatabaseHas('processing_material_usages', [
            'processing_batch_id' => $batch->id,
            'source_type' => 'warehouse_withdrawal',
            'source_id' => $withdrawal->id,
            'inventory_lot_id' => $lot->id,
            'quantity' => 2,
            'unit_name' => 'kg',
        ]);
        $this->assertSame('10.0000', $lot->fresh()->balance_quantity);

        $item = $withdrawal->items()->firstOrFail();
        $service->verify($withdrawal, $warehouse, [$item->id => 1.5]);

        $this->assertDatabaseHas('processing_material_usages', [
            'processing_batch_id' => $batch->id,
            'source_type' => 'warehouse_withdrawal',
            'source_id' => $withdrawal->id,
            'quantity' => 1.5,
        ]);
        $this->assertSame('8.5000', $lot->fresh()->balance_quantity);
        $this->assertSame(0, $batch->temperatureLogs()->count());
    }

    public function test_processing_completion_requires_temperature_and_finished_output_documentations(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->readyInProgressBatch($unit, $ingredient, $staff);

        app(ProcessingWorkflow::class)->complete($batch, $staff);

        $this->assertSame(ProcessingBatchState::Completed, $batch->fresh()->state);
        $this->assertDatabaseHas('processing_histories', [
            'processing_batch_id' => $batch->id,
            'action' => 'production_completed',
        ]);
    }

    public function test_processing_completion_rejects_missing_temperature_photo(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->readyInProgressBatch($unit, $ingredient, $staff);
        $batch->temperatureLogs()->update(['photo_path' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('foto pengukuran suhu');

        app(ProcessingWorkflow::class)->complete($batch, $staff);
    }

    public function test_processing_completion_rejects_missing_finished_output_photo(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->readyInProgressBatch($unit, $ingredient, $staff);
        $batch->documentations()->delete();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Foto berat atau jumlah makanan jadi');

        app(ProcessingWorkflow::class)->complete($batch, $staff);
    }

    public function test_processing_form_stores_temperature_and_finished_output_documentations_separately(): void
    {
        Storage::fake('public');
        [$unit, $ingredient, $staff] = $this->baseData();
        $batch = $this->batch($unit, $staff);
        $batch->update([
            'state' => ProcessingBatchState::InProgress,
            'started_at' => now()->subHour(),
        ]);
        $batch->materialUsages()->create([
            'ingredient_id' => $ingredient->id,
            'material_name' => $ingredient->name,
            'quantity' => 5,
            'unit_name' => 'kg',
            'condition_status' => 'good',
        ]);

        Livewire::actingAs($staff)
            ->test(ProcessingIndex::class)
            ->call('select', $batch->id)
            ->set('actualOutputQuantity', '24')
            ->set('actualOutputUnit', 'loyang')
            ->set('cookedProducts.0.product_name', 'Ayam Matang')
            ->set('cookedProducts.0.temperature_celsius', '76')
            ->set('temperaturePhotos.0', UploadedFile::fake()->image('foto-suhu.jpg'))
            ->set('outputPhoto', UploadedFile::fake()->image('hasil-24-loyang.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $batch->refresh();
        $temperature = $batch->temperatureLogs()->sole();
        $outputDocumentation = $batch->documentations()
            ->where('documentation_type', 'finished_output')
            ->sole();

        $this->assertSame('24.000', $batch->actual_output_quantity);
        $this->assertSame('loyang', $batch->actual_output_unit);
        $this->assertStringStartsWith('processing/temperature/', $temperature->photo_path);
        $this->assertStringStartsWith('processing/finished-output/', $outputDocumentation->photo_path);
        $this->assertSame('Hasil akhir 24 loyang', $outputDocumentation->caption);
        $this->assertDatabaseMissing('processing_documentations', [
            'processing_batch_id' => $batch->id,
            'documentation_type' => 'after',
        ]);
        Storage::disk('public')->assertExists($temperature->photo_path);
        Storage::disk('public')->assertExists($outputDocumentation->photo_path);
    }

    public function test_processing_report_uses_division_head_then_sppg_head_approval(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $head = $this->userWithPermission('processing.approve');
        $head->assignRole(Role::findOrCreate(UserRole::KepalaDivisiPengolahan->value));
        $sppgHead = $this->userWithPermission('processing.approve');
        $sppgHead->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));
        $batch = $this->readyInProgressBatch($unit, $ingredient, $staff);
        $workflow = app(ProcessingWorkflow::class);
        $workflow->complete($batch->fresh(), $staff);
        $workflow->submit($batch->fresh(), $staff);
        $workflow->verify($batch->fresh(), $head, 'Sesuai catatan produksi.');

        $this->assertSame(OperationalReportStatus::DivisionApproved, $batch->fresh()->status);
        $this->assertSame($head->id, $batch->fresh()->division_approved_by);

        $workflow->verify($batch->fresh(), $sppgHead, 'Disetujui Kepala SPPG.');
        $this->assertSame(OperationalReportStatus::Verified, $batch->fresh()->status);
        $this->assertSame($sppgHead->id, $batch->fresh()->verified_by);
    }

    public function test_processing_return_restores_stock_only_after_warehouse_verification(): void
    {
        [$unit, $ingredient, $staff] = $this->baseData();
        $warehouse = $this->userWithPermission('stock.approve');
        $batch = $this->batch($unit, $staff);
        $batch->update(['state' => ProcessingBatchState::InProgress]);
        $lot = $this->lot($unit, $ingredient, 8);
        $usage = $batch->materialUsages()->create([
            'ingredient_id' => $ingredient->id,
            'inventory_lot_id' => $lot->id,
            'material_name' => $ingredient->name,
            'quantity' => 2,
            'unit_name' => 'kg',
            'condition_status' => 'good',
        ]);

        $service = app(ProcessingReturnService::class);
        $return = $service->submit($batch, $usage, 1, 'Tidak digunakan.', $staff);
        $this->assertSame('8.0000', $lot->fresh()->balance_quantity);
        $this->assertSame(ProcessingReturn::WAITING, $return->status);

        $service->verify($return, 1, 'available', 'Jumlah sesuai.', $warehouse);

        $this->assertSame('9.0000', $lot->fresh()->balance_quantity);
        $this->assertSame(ProcessingReturn::VERIFIED, $return->fresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => ProcessingReturn::class,
            'source_id' => $return->id,
            'quantity_in' => 1,
        ]);
    }

    /** @return array{SppgUnit, Ingredient, User} */
    private function baseData(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-PROCESS',
            'name' => 'SPPG Pengolahan',
            'slug' => 'sppg-pengolahan',
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'BHN-PROCESS',
            'name' => 'Daging Ayam',
            'is_active' => true,
        ]);

        return [$unit, $ingredient, $this->userWithPermission('processing.view', 'processing.update', 'processing.submit', 'preparation.update')];
    }

    private function batch(SppgUnit $unit, User $staff): ProcessingBatch
    {
        return ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id,
            'production_date' => today(),
            'menu_name_snapshot' => 'Nasi dan Ayam',
            'product_name' => 'Ayam Matang',
            'target_output_quantity' => 100,
            'target_output_unit' => 'porsi',
            'state' => ProcessingBatchState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $staff->id,
        ]);
    }

    private function readyInProgressBatch(SppgUnit $unit, Ingredient $ingredient, User $staff): ProcessingBatch
    {
        $batch = $this->batch($unit, $staff);
        $batch->update([
            'state' => ProcessingBatchState::InProgress,
            'actual_output_quantity' => 100,
            'actual_output_unit' => 'porsi',
            'started_at' => now()->subHour(),
        ]);
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
            'product_name' => 'Ayam Matang',
            'temperature_celsius' => 75,
            'measured_by' => $staff->id,
            'photo_path' => 'test/temperature.jpg',
        ]);
        $batch->documentations()->create([
            'documentation_type' => 'finished_output',
            'caption' => 'Hasil akhir 100 porsi',
            'photo_path' => 'test/finished-output.jpg',
            'captured_at' => now(),
        ]);

        return $batch;
    }

    private function lot(SppgUnit $unit, Ingredient $ingredient, float $balance): InventoryLot
    {
        return InventoryLot::query()->create([
            'sppg_unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'unit_snapshot' => 'kg',
            'initial_quantity' => $balance,
            'balance_quantity' => $balance,
            'initial_quantity_kg' => $balance,
            'balance_quantity_kg' => $balance,
            'lot_number' => 'LOT-PROCESS',
            'storage_type' => 'dry',
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
