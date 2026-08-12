<?php

use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\PreparationSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\PreparationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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
    $session->items()->create([
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
    $session->resultDocumentation()->create([
        'photo_path' => 'mobile/persiapan/hasil.jpg',
        'captured_at' => now(),
        'created_by' => $user->id,
    ]);

    app(PreparationSessionService::class)->complete($session, $user);

    expect($session->refresh()->state)->toBe('completed')
        ->and($withdrawal->refresh()->status)->toBe(WarehouseWithdrawal::WAITING);
});

it('saves required preparation result photo before inserting its documentation row', function (): void {
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
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));

    $this->postJson("/api/mobile/operational-modules/persiapan/records/{$session->id}/relations/resultDocumentation", [
        'fields' => [],
        'files' => ['photo_path' => $photo],
    ])->assertCreated()
        ->assertJsonPath('message', 'Foto hasil Persiapan berhasil disimpan.');

    $documentation = $session->resultDocumentation()->firstOrFail();
    expect($documentation->photo_path)->not->toBeNull()
        ->and($documentation->captured_at)->not->toBeNull();
    Storage::disk('public')->assertExists($documentation->photo_path);
});

it('creates a preparation output from a dropdown item without manual name or unit', function (): void {
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

    $this->postJson('/api/mobile/operational-modules/hasil-persiapan/records', [
        'fields' => [
            'preparation_session_item_id' => $sourceItem->id,
            'quantity' => 5,
            'target_division' => 'both',
            'storage_location' => 'langsung_digunakan',
            'expires_at' => now()->addHours(2)->toIso8601String(),
            'notes' => null,
        ],
        'files' => [],
    ])->assertCreated();

    $output = $session->outputs()->firstOrFail();
    expect($output->output_name)->toBe('Wortel Bersih')
        ->and($output->unit_snapshot)->toBe('kg')
        ->and($output->storage_location)->toBe('langsung_digunakan')
        ->and((float) $output->quantity)->toBe(5.0);
});
