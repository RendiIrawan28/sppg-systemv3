<?php

use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use App\Services\OpeningStockService;
use App\Services\WarehouseWithdrawalService;
use App\Support\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-NF', 'name' => 'SPPG Non-Pangan', 'slug' => 'sppg-non-pangan', 'is_active' => true,
    ]);
    $this->actor = User::query()->create([
        'name' => 'Operator Non-Pangan', 'email' => 'non-pangan@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->pieces = MeasurementUnit::query()->create([
        'code' => 'box-nf', 'name' => 'Box', 'symbol' => 'box', 'unit_type' => 'count',
        'to_base_factor' => 1, 'is_active' => true,
    ]);
    $this->item = NonFoodItem::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'measurement_unit_id' => $this->pieces->id,
        'code' => 'NF-GLOVE',
        'name' => 'Sarung Tangan',
        'category' => 'APD',
        'minimum_stock' => 5,
        'target_stock' => 20,
        'default_location' => 'Rak APD',
        'tracks_lot' => false,
        'tracks_expiry' => false,
        'is_active' => true,
    ]);
});

it('records non-food opening stock through inventory lot and stock movement', function (): void {
    $opening = app(OpeningStockService::class)->createForWarehouse(
        $this->unit->id,
        today()->toDateString(),
        'tests/stok-awal-non-pangan.jpg',
        'Stok fisik sebelum sistem',
        [[
            'mode' => 'existing',
            'non_food_item_id' => $this->item->id,
            'quantity' => 25,
            'lot_number' => null,
            'expired_date' => null,
            'storage_type' => 'dry',
            'location_name' => 'Rak APD',
            'condition_notes' => 'Baik',
        ]],
        $this->actor,
        Warehouse::TYPE_NON_FOOD,
    );

    $lot = InventoryLot::query()->where('non_food_item_id', $this->item->id)->firstOrFail();
    $movement = StockMovement::query()->where('inventory_lot_id', $lot->id)->firstOrFail();

    expect($opening->warehouse->type)->toBe(Warehouse::TYPE_NON_FOOD)
        ->and($lot->ingredient_id)->toBeNull()
        ->and((float) $lot->balance_quantity)->toBe(25.0)
        ->and((float) $lot->balance_quantity_kg)->toBe(0.0)
        ->and($movement->movement_type)->toBe(StockMovement::TYPE_OPENING_BALANCE)
        ->and((float) $movement->quantity_in)->toBe(25.0);
});

it('reserves non-food withdrawals and only reduces stock after warehouse verification', function (): void {
    $warehouse = Warehouse::forUnit($this->unit->id, Warehouse::TYPE_NON_FOOD);
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $this->unit->id, 'warehouse_id' => $warehouse->id,
        'ingredient_id' => null, 'non_food_item_id' => $this->item->id,
        'unit_snapshot' => 'box', 'initial_quantity' => 10, 'balance_quantity' => 10,
        'initial_quantity_kg' => 0, 'balance_quantity_kg' => 0,
        'lot_number' => 'NF-FIFO-1', 'storage_type' => 'dry', 'status' => InventoryLot::AVAILABLE,
    ]);
    $service = app(WarehouseWithdrawalService::class);
    $first = $service->createNonFoodDraft($this->unit->id, 'kebersihan', 'Operasional harian', null, $this->actor);
    $service->addNonFoodItem($first, $lot->id, 7, 'tests/ambil-nf.jpg', null, $this->actor);
    $service->submit($first->refresh(), $this->actor);

    expect((float) $lot->refresh()->balance_quantity)->toBe(10.0)
        ->and($first->refresh()->status)->toBe(WarehouseWithdrawal::WAITING);

    $second = $service->createNonFoodDraft($this->unit->id, 'pencucian', 'Operasional harian', null, $this->actor);
    expect(fn () => $service->addNonFoodItem($second, $lot->id, 4, 'tests/ambil-nf-2.jpg', null, $this->actor))
        ->toThrow(ValidationException::class);

    $service->verify($first->refresh(), $this->actor);
    expect((float) $lot->refresh()->balance_quantity)->toBe(3.0)
        ->and(StockMovement::query()->where('source_id', $first->id)->where('quantity_out', 7)->exists())->toBeTrue();
});

it('keeps non-food permissions scoped away from food procurement editing', function (): void {
    $warehousePermissions = AccessControl::permissionsForRole('staf_gudang');
    $divisionPermissions = AccessControl::permissionsForRole('petugas_kebersihan');

    expect($warehousePermissions)->toContain('non_food_items.manage', 'non_food_procurement.create', 'non_food_stock.approve')
        ->not->toContain('procurement.update')
        ->and($divisionPermissions)->toContain('non_food_stock.create', 'non_food_stock.update')
        ->not->toContain('non_food_procurement.create');
});
