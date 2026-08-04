<?php

use App\Livewire\V3\Warehouse\OpeningStocks\Index;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\OpeningStock;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-STOK', 'name' => 'SPPG Stok', 'slug' => 'sppg-stok', 'is_active' => true,
    ]);
    $this->superAdmin = User::query()->create([
        'name' => 'Super Admin', 'email' => 'super-stok@example.test', 'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->viewer = User::query()->create([
        'name' => 'Viewer Stok', 'email' => 'viewer-stok@example.test', 'password' => 'password', 'is_active' => true,
    ]);
    $this->viewer->givePermissionTo(Permission::findOrCreate('stock.view', 'web'));
    $this->kilogram = MeasurementUnit::query()->create([
        'code' => 'kg', 'name' => 'Kilogram', 'symbol' => 'kg', 'unit_type' => 'weight', 'to_base_factor' => 1000, 'is_active' => true,
    ]);
    $this->pieces = MeasurementUnit::query()->create([
        'code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'unit_type' => 'count', 'to_base_factor' => 1, 'is_active' => true,
    ]);
    $this->liters = MeasurementUnit::query()->create([
        'code' => 'l', 'name' => 'Liter', 'symbol' => 'l', 'unit_type' => 'volume', 'to_base_factor' => 1000, 'is_active' => true,
    ]);
    $this->grams = MeasurementUnit::query()->create([
        'code' => 'g', 'name' => 'Gram', 'symbol' => 'g', 'unit_type' => 'weight', 'to_base_factor' => 1, 'is_active' => true,
    ]);
    $this->rice = Ingredient::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'measurement_unit_id' => $this->kilogram->id,
        'code' => 'BERAS',
        'name' => 'Beras',
        'category' => 'staple',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
});

it('activates several opening stock items with one photo and can create a new ingredient', function (): void {
    Storage::fake('public');

    Livewire::actingAs($this->superAdmin)
        ->test(Index::class)
        ->set('openingDate', '2026-08-04')
        ->set('notes', 'Perhitungan awal gudang')
        ->set('photo', UploadedFile::fake()->image('stok-awal.jpg'))
        ->set('rows', [
            [
                'mode' => 'existing', 'ingredient_id' => $this->rice->id, 'new_name' => '', 'new_category' => 'other',
                'measurement_unit_id' => '', 'quantity' => '125.5', 'lot_number' => '', 'expired_date' => '',
                'storage_type' => 'dry', 'location_name' => 'Rak A', 'condition_notes' => 'Baik',
            ],
            [
                'mode' => 'new', 'ingredient_id' => '', 'new_name' => 'Sarung Tangan', 'new_category' => 'other',
                'measurement_unit_id' => $this->pieces->id, 'quantity' => '40', 'lot_number' => 'ST-01', 'expired_date' => '',
                'storage_type' => 'dry', 'location_name' => 'Rak B', 'condition_notes' => '',
            ],
            [
                'mode' => 'new', 'ingredient_id' => '', 'new_name' => 'Minyak Cair', 'new_category' => 'oil',
                'measurement_unit_id' => $this->liters->id, 'quantity' => '12.5', 'lot_number' => 'MC-01', 'expired_date' => '',
                'storage_type' => 'dry', 'location_name' => 'Rak C', 'condition_notes' => '',
            ],
            [
                'mode' => 'new', 'ingredient_id' => '', 'new_name' => 'Bumbu Gram', 'new_category' => 'seasoning',
                'measurement_unit_id' => $this->grams->id, 'quantity' => '2500', 'lot_number' => 'BG-01', 'expired_date' => '',
                'storage_type' => 'dry', 'location_name' => 'Rak D', 'condition_notes' => '',
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $opening = OpeningStock::query()->with('items')->first();
    expect($opening)->not->toBeNull()
        ->and($opening->status)->toBe('active')
        ->and($opening->items)->toHaveCount(4)
        ->and(Ingredient::query()->where('name', 'Sarung Tangan')->exists())->toBeTrue()
        ->and(InventoryLot::query()->count())->toBe(4)
        ->and(StockMovement::query()->where('movement_type', StockMovement::TYPE_OPENING_BALANCE)->count())->toBe(4)
        ->and((float) StockMovement::query()->where('ingredient_id', $this->rice->id)->value('quantity_in'))->toBe(125.5)
        ->and((float) InventoryLot::query()->whereHas('ingredient', fn ($query) => $query->where('name', 'Sarung Tangan'))->value('balance_quantity'))->toBe(40.0)
        ->and((float) InventoryLot::query()->whereHas('ingredient', fn ($query) => $query->where('name', 'Sarung Tangan'))->value('balance_quantity_kg'))->toBe(0.0)
        ->and((float) InventoryLot::query()->whereHas('ingredient', fn ($query) => $query->where('name', 'Minyak Cair'))->value('balance_quantity'))->toBe(12.5)
        ->and((float) InventoryLot::query()->whereHas('ingredient', fn ($query) => $query->where('name', 'Minyak Cair'))->value('balance_quantity_kg'))->toBe(0.0)
        ->and((float) InventoryLot::query()->whereHas('ingredient', fn ($query) => $query->where('name', 'Bumbu Gram'))->value('balance_quantity_kg'))->toBe(2.5);
    Storage::disk('public')->assertExists($opening->photo_path);
});

it('allows only stock creators or super admins to open the page', function (): void {
    $this->actingAs($this->viewer)
        ->get(route('v3.warehouse.opening-stocks.index'))
        ->assertForbidden();

    $this->actingAs($this->superAdmin)
        ->get(route('v3.warehouse.opening-stocks.index'))
        ->assertOk()
        ->assertSee('Masukkan persediaan yang sudah ada')
        ->assertSee('Ketik nama, kode, atau satuan barang');
});
