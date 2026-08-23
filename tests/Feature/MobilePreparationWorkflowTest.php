<?php

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\PreparationReturn;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Models\WasteHandoverReport;
use App\Services\PreparationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-08-24 09:00:00'));
    Storage::fake('public');
});

it('allows preparation to finish while warehouse verification is still pending', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PREP',
        'name' => 'SPPG Persiapan',
        'slug' => 'sppg-persiapan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Persiapan',
        'email' => 'persiapan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg-prep',
        'name' => 'Kilogram Persiapan',
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'measurement_unit_id' => $measurementUnit->id,
        'code' => 'TEMPE-PREP',
        'name' => 'Tempe',
        'category' => 'plant_protein',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $user->id,
    ]);
    $session = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $withdrawal->id,
        'session_number' => 'PS/TEST/0001',
        'preparation_date' => today(),
        'state' => 'in_progress',
        'status' => 'draft',
        'petugas_id' => $user->id,
        'started_at' => now()->subHour(),
    ]);
    $item = $session->items()->create([
        'ingredient_id' => $ingredient->id,
        'ingredient_name_snapshot' => $ingredient->name,
        'unit_snapshot' => 'kg',
        'received_quantity' => 10,
        'processed_quantity' => 9,
        'waste_quantity' => 1,
        'received_weight_kg' => 10,
        'clean_weight_kg' => 9,
        'waste_weight_kg' => 1,
    ]);
    $item->resultDocumentation()->create([
        'preparation_session_id' => $session->id,
        'photo_path' => 'mobile/persiapan/hasil.jpg',
        'captured_at' => now(),
        'created_by' => $user->id,
    ]);

    app(PreparationSessionService::class)->complete($session, $user);

    expect($session->refresh()->state)->toBe('completed')
        ->and($withdrawal->refresh()->status)->toBe(WarehouseWithdrawal::WAITING);
});

it('saves a result photo on each preparation item', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PREP-PHOTO',
        'name' => 'SPPG Foto Persiapan',
        'slug' => 'sppg-foto-persiapan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Foto Persiapan',
        'email' => 'foto-persiapan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    Sanctum::actingAs($user, ['mobile']);
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $user->id,
    ]);
    $session = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $withdrawal->id,
        'session_number' => 'PS/PHOTO/0001',
        'preparation_date' => today(),
        'state' => 'in_progress',
        'status' => 'draft',
        'petugas_id' => $user->id,
        'started_at' => now(),
    ]);
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg-photo', 'name' => 'Kilogram Foto', 'symbol' => 'kg',
        'unit_type' => 'weight', 'to_base_factor' => 1000, 'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id, 'measurement_unit_id' => $measurementUnit->id,
        'code' => 'WORTEL-PHOTO', 'name' => 'Wortel', 'category' => 'vegetable',
        'edible_portion_percent' => 100, 'loss_factor' => 1, 'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100, 'is_active' => true,
    ]);
    $item = $session->items()->create([
        'ingredient_id' => $ingredient->id,
        'ingredient_name_snapshot' => 'Wortel',
        'unit_snapshot' => 'kg',
        'received_quantity' => 5,
        'received_weight_kg' => 5,
        'clean_weight_kg' => 0,
        'waste_weight_kg' => 0,
    ]);
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));

    $this->putJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/relations/items/{$item->id}", [
        'fields' => [
            'condition_status' => 'good',
            'processed_quantity' => 4,
            'waste_quantity' => 1,
            'output_target_division' => 'processing',
            'notes' => null,
        ],
        'files' => ['result_photo_path' => $photo],
    ])->assertOk();

    $documentation = $item->resultDocumentation()->firstOrFail();
    expect($documentation->photo_path)->not->toBeNull()
        ->and($documentation->captured_at)->not->toBeNull();
    Storage::disk('public')->assertExists($documentation->photo_path);
});

it('creates preparation output automatically from an updated preparation item', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PREP-OUTPUT',
        'name' => 'SPPG Hasil Persiapan',
        'slug' => 'sppg-hasil-persiapan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Hasil Persiapan',
        'email' => 'hasil-persiapan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    Sanctum::actingAs($user, ['mobile']);
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg-output',
        'name' => 'Kilogram Output',
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'measurement_unit_id' => $measurementUnit->id,
        'code' => 'WORTEL-OUTPUT',
        'name' => 'Wortel Bersih',
        'category' => 'vegetable',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $user->id,
    ]);
    $session = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $withdrawal->id,
        'session_number' => 'PS/OUTPUT/0001',
        'preparation_date' => today(),
        'state' => 'in_progress',
        'status' => 'draft',
        'petugas_id' => $user->id,
        'started_at' => now(),
    ]);
    $sourceItem = $session->items()->create([
        'ingredient_id' => $ingredient->id,
        'ingredient_name_snapshot' => $ingredient->name,
        'unit_snapshot' => 'kg',
        'received_quantity' => 6,
        'processed_quantity' => 5,
        'waste_quantity' => 1,
        'received_weight_kg' => 6,
        'clean_weight_kg' => 5,
        'waste_weight_kg' => 1,
    ]);

    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));
    $this->putJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/relations/items/{$sourceItem->id}", [
        'fields' => [
            'condition_status' => 'good',
            'processed_quantity' => 5,
            'waste_quantity' => 1,
            'output_target_division' => 'processing',
            'notes' => null,
        ],
        'files' => ['result_photo_path' => $photo],
    ])->assertOk();

    $this->putJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/relations/items/{$sourceItem->id}", [
        'fields' => [
            'condition_status' => 'good',
            'processed_quantity' => 5,
            'waste_quantity' => 1,
            'output_target_division' => 'processing',
            'notes' => null,
        ],
        'files' => [],
    ])->assertOk();

    $output = $session->outputs()->firstOrFail();
    $wasteReport = WasteHandoverReport::query()->sole();
    expect($output->output_name)->toBe('Wortel Bersih siap')
        ->and($output->unit_snapshot)->toBe('kg')
        ->and($output->target_division)->toBe('processing')
        ->and((float) $output->quantity)->toBe(5.0)
        ->and(WasteHandoverReport::query()->count())->toBe(1)
        ->and($wasteReport->items()->count())->toBe(1)
        ->and((float) $wasteReport->items()->sole()->quantity)->toBe(1.0);

    $this->postJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/actions/complete")
        ->assertOk();
    $actions = collect($this->getJson("/api/mobile/operational-modules/persiapan/records/{$session->id}")
        ->assertOk()->json('data.capabilities.actions'))->pluck('key');
    expect($actions)->toContain('handover_processing');

    $this->postJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/actions/handover_processing", [
        'fields' => [
            'preparation_output_id' => $output->id,
            'requested_quantity' => 5,
        ],
    ])->assertOk();

    $handover = PreparationOutputWithdrawal::query()->sole();
    expect($handover->destination_division)->toBe('processing')
        ->and($handover->status)->toBe(PreparationOutputWithdrawal::WAITING)
        ->and($handover->processing_batch_id)->toBeNull();

    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Uji',
        'product_name' => 'Menu Uji',
        'target_output_quantity' => 5,
        'target_output_unit' => 'kg',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
        'petugas_id' => $user->id,
        'petugas_name_snapshot' => $user->name,
    ]);
    $processingActions = collect($this->getJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}")
        ->assertOk()->json('data.capabilities.actions'))->pluck('key');
    expect($processingActions)->toContain('receive_preparation_output');

    $this->postJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}/actions/receive_preparation_output", [
        'fields' => ['withdrawal_id' => $handover->id],
    ])->assertOk();

    expect($handover->refresh()->status)->toBe(PreparationOutputWithdrawal::VERIFIED)
        ->and($handover->processing_batch_id)->toBe($batch->id);
});

it('submits a preparation return on mobile and restores stock only after warehouse verification', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PREP-RETURN',
        'name' => 'SPPG Retur Persiapan',
        'slug' => 'sppg-retur-persiapan',
        'is_active' => true,
    ]);
    $preparationUser = User::query()->create([
        'name' => 'Petugas Retur Persiapan',
        'email' => 'retur-persiapan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $warehouseUser = User::query()->create([
        'name' => 'Petugas Verifikasi Gudang',
        'email' => 'verifikasi-retur@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg-return',
        'name' => 'Kilogram Retur',
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'measurement_unit_id' => $measurementUnit->id,
        'code' => 'TEMPE-RETURN',
        'name' => 'Tempe Retur',
        'category' => 'plant_protein',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $unit->id,
        'ingredient_id' => $ingredient->id,
        'unit_snapshot' => 'kg',
        'initial_quantity' => 10,
        'balance_quantity' => 4,
        'initial_quantity_kg' => 10,
        'balance_quantity_kg' => 4,
        'lot_number' => 'LOT-RETUR-01',
        'storage_type' => 'dry',
        'status' => InventoryLot::AVAILABLE,
    ]);
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $preparationUser->id,
    ]);
    $session = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $withdrawal->id,
        'session_number' => 'PS/RETURN/0001',
        'preparation_date' => today(),
        'state' => 'in_progress',
        'status' => 'draft',
        'petugas_id' => $preparationUser->id,
        'started_at' => now(),
    ]);
    $item = $session->items()->create([
        'ingredient_id' => $ingredient->id,
        'inventory_lot_id' => $lot->id,
        'ingredient_name_snapshot' => $ingredient->name,
        'unit_snapshot' => 'kg',
        'received_quantity' => 5,
        'received_weight_kg' => 5,
    ]);
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));

    Sanctum::actingAs($preparationUser, ['mobile']);
    $this->postJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/relations/returns", [
        'fields' => [
            'preparation_session_item_id' => $item->id,
            'requested_quantity' => 2.5,
            'condition_status' => 'good',
            'reason' => 'Tidak digunakan dalam proses persiapan.',
        ],
        'files' => ['photo_path' => $photo],
    ])->assertCreated()
        ->assertJsonPath('message', 'Retur berhasil diajukan dan menunggu verifikasi Gudang.');

    $return = PreparationReturn::query()->sole();
    expect($return->status)->toBe(PreparationReturn::WAITING)
        ->and((float) $lot->refresh()->balance_quantity)->toBe(4.0);
    Storage::disk('public')->assertExists($return->photo_path);

    Sanctum::actingAs($warehouseUser, ['mobile']);
    $this->postJson("/api/mobile/operational-modules/gudang-retur/records/{$return->id}/actions/verify", [
        'notes' => 'Jumlah dan kondisi sesuai pemeriksaan fisik.',
        'fields' => [
            'actual_quantity' => 2.5,
            'warehouse_disposition' => 'available',
        ],
        'files' => [],
    ])->assertOk()
        ->assertJsonPath('data.status', PreparationReturn::VERIFIED);

    expect((float) $return->refresh()->actual_quantity)->toBe(2.5)
        ->and((float) $lot->refresh()->balance_quantity)->toBe(6.5)
        ->and(StockMovement::query()->where('source_type', PreparationReturn::class)
            ->where('source_id', $return->id)->exists())->toBeTrue();
});
