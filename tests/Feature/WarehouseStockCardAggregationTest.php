<?php

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\SppgUnit;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OpeningStockService;
use App\Services\StockControlService;
use App\Services\StockReceiptService;
use App\Services\WarehouseStockCardService;
use App\Support\V3\OperationsPresentation;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedStockCardDatabase;

uses(IsolatedStockCardDatabase::class);

beforeEach(function () {
    $this->unit = SppgUnit::create(['code' => 'TEST', 'name' => 'Test', 'is_active' => true]);
    $this->actor = User::create(['name' => 'Gudang', 'email' => 'stock@example.test', 'password' => 'password', 'is_active' => true, 'is_super_admin' => true]);
    $this->actingAs($this->actor);
    Sanctum::actingAs($this->actor, ['mobile']);
    $this->measure = MeasurementUnit::create(['code' => 'kg', 'symbol' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'to_base_factor' => 1000, 'is_active' => true]);
    $this->ingredient = Ingredient::create(['sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->measure->id, 'code' => 'B001', 'name' => 'BERAS', 'is_active' => true]);
    $this->supplier = Supplier::create(['sppg_unit_id' => $this->unit->id, 'code' => 'SUP', 'name' => 'Supplier', 'is_active' => true]);
    $this->warehouse = Warehouse::forUnit($this->unit->id, 'food');
    $this->cards = fn () => app(WarehouseStockCardService::class)->cards($this->unit->id, $this->warehouse->id);
    $this->receive = function (float $quantity, string $batch = 'LOT-A', ?Ingredient $ingredient = null) {
        $receipt = app(StockReceiptService::class)->createManual($this->unit->id, $this->warehouse->id, $this->supplier->id, '2026-09-04', null, [[
            'ingredient_id' => ($ingredient ?? $this->ingredient)->id, 'received_quantity' => $quantity, 'accepted_quantity' => $quantity, 'rejected_quantity' => 0, 'supplier_batch_number' => $batch,
        ]], $this->actor);
        $receipt->items->first()->photos()->create(['stock_receipt_id' => $receipt->id, 'photo_path' => 'test.jpg']);
        app(StockReceiptService::class)->receive($receipt);

        return $receipt;
    };
});

it('keeps two receipts and lots but only one card even for identical supplier batches', function (string $batch) {
    ($this->receive)(100);
    ($this->receive)(50, $batch);
    $cards = ($this->cards)();
    expect($cards)->toHaveCount(1)
        ->and($cards->first()->balance_quantity)->toBe(150.0)
        ->and($cards->first()->active_lot_count)->toBe(2)
        ->and(InventoryLot::count())->toBe(2)->and(StockMovement::count())->toBe(2)->and(Ingredient::count())->toBe(1)
        ->and($cards->pluck('ingredient_id')->unique()->count())->toBe($cards->count());
})->with(['LOT-A', 'LOT-B']);

it('combines opening stock and receipt and reduces the same card after an outgoing movement', function () {
    app(OpeningStockService::class)->create($this->unit->id, '2026-09-03', 'test.jpg', null, [[
        'ingredient_id' => $this->ingredient->id, 'quantity' => 100, 'lot_number' => 'OPEN', 'expired_date' => null, 'storage_type' => 'dry',
    ]], $this->actor);
    ($this->receive)(50);
    $lot = InventoryLot::first();
    $adjustment = app(StockControlService::class)->create($lot, 80, 'damage', 'Rusak', $this->actor);
    app(StockControlService::class)->verify($adjustment, $this->actor);
    expect(($this->cards)())->toHaveCount(1)->and(($this->cards)()->first()->balance_quantity)->toBe(130.0);
    $ledger = app(WarehouseStockCardService::class)->ledger($this->unit->id, $this->warehouse->id, $this->ingredient->id);
    expect((float) $ledger->last()->running_balance)->toBe(130.0)->and(StockMovement::count())->toBe(3);
});

it('uses master identity despite renamed snapshots and filters locations without changing total balance', function () {
    ($this->receive)(30);
    $this->ingredient->update(['name' => 'BERAS BARU']);
    ($this->receive)(20, 'LOT-B');
    InventoryLot::first()->update(['location_name' => 'Freezer', 'storage_type' => 'freezer']);
    InventoryLot::orderByDesc('id')->first()->update(['location_name' => 'Chiller', 'storage_type' => 'chiller']);
    $service = app(WarehouseStockCardService::class);
    foreach (['B001', 'BERAS BARU', 'Freezer', 'Chiller'] as $term) {
        $cards = $service->cards($this->unit->id, $this->warehouse->id, $term);
        expect($cards)->toHaveCount(1)->and($cards->first()->balance_quantity)->toBe(50.0);
    }
    expect(StockMovement::first()->ingredient_name_snapshot)->toBe('BERAS');
});

it('keeps different ingredients separate and excludes quarantined and depleted lots from active count', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $other = $this->ingredient->replicate();
    $other->code = 'A001';
    $other->name = 'AYAM';
    $other->save();
    ($this->receive)(20, 'AYAM', $other);
    InventoryLot::first()->update(['status' => 'quarantine']);
    $cards = ($this->cards)();
    expect($cards)->toHaveCount(2)->and($cards->firstWhere('ingredient_id', $this->ingredient->id)->active_lot_count)->toBe(1);
    InventoryLot::where('lot_number', 'LOT-B')->update(['balance_quantity' => 0, 'status' => 'depleted']);
    expect(($this->cards)()->firstWhere('ingredient_id', $this->ingredient->id)->active_lot_count)->toBe(0);
});

it('converts historical purchase sacks using the already recorded weight', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    StockMovement::orderByDesc('id')->first()->update(['quantity_in' => 2, 'unit_snapshot' => 'sak']);
    expect(($this->cards)())->toHaveCount(1)->and(($this->cards)()->first()->balance_quantity)->toBe(150.0);
});

it('does not sum incompatible units silently', function () {
    ($this->receive)(100);
    StockMovement::first()->update(['unit_snapshot' => 'unknown', 'quantity_in_kg' => 0]);
    expect(($this->cards)()->first()->balance_quantity)->toBeNull()
        ->and(($this->cards)()->first()->conversion_warning)->not->toBeNull();
});

it('preserves non food receipt behavior and excludes it from food cards', function () {
    $warehouse = Warehouse::forUnit($this->unit->id, 'non_food');
    $item = NonFoodItem::create(['sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->measure->id, 'code' => 'NF1', 'name' => 'Sabun', 'is_active' => true]);
    $receipt = app(StockReceiptService::class)->createManual($this->unit->id, $warehouse->id, $this->supplier->id, '2026-09-04', null, [[
        'non_food_item_id' => $item->id, 'received_quantity' => 20, 'accepted_quantity' => 20, 'rejected_quantity' => 0,
    ]], $this->actor);
    $receipt->items->first()->photos()->create(['stock_receipt_id' => $receipt->id, 'photo_path' => 'test.jpg']);
    app(StockReceiptService::class)->receive($receipt);
    expect(($this->cards)())->toHaveCount(0)->and(InventoryLot::first()->non_food_item_id)->toBe($item->id)
        ->and((float) StockMovement::first()->quantity_in)->toBe(20.0);
});

it('returns one mobile card and full multi lot detail regardless of default date filter', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $id = InventoryLot::first()->id;
    $this->getJson('/api/mobile/operational-modules/gudang-stok/records?date_from=2020-01-01&date_to=2020-01-01&search=B001')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'BERAS');
    $this->getJson("/api/mobile/operational-modules/gudang-stok/records/{$id}")
        ->assertOk()->assertJsonCount(2, 'data.sections.0.items')->assertJsonCount(2, 'data.sections.1.items')
        ->assertJsonPath('data.fields.3.value', '150,000');
});

it('requires an explicit lot for multi lot corrections and rejects a different ingredient', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $id = InventoryLot::first()->id;
    $this->postJson("/api/mobile/operational-modules/gudang-stok/records/{$id}/actions/adjust_stock", ['fields' => [
        'actual_quantity' => 70, 'adjustment_type' => 'stock_opname', 'reason' => 'Hitung ulang',
    ]])->assertUnprocessable();
    expect(StockAdjustment::count())->toBe(0);
    $this->postJson("/api/mobile/operational-modules/gudang-stok/records/{$id}/actions/adjust_stock", ['fields' => [
        'inventory_lot_id' => 999, 'actual_quantity' => 70, 'adjustment_type' => 'stock_opname', 'reason' => 'Hitung ulang',
    ]])->assertNotFound();
});

it('exports one summary row per ingredient and separate ledger rows', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $response = $this->get('/v3/gudang/stok/ekspor')->assertOk();
    expect(substr_count($response->streamedContent(), 'BERAS'))->toBe(1);
    $response = $this->get('/v3/gudang/stok/ekspor?bahan='.$this->ingredient->id)->assertOk();
    expect(substr_count($response->streamedContent(), 'PBM/2026/'))->toBe(2);
});

it('updates only the selected lot and returns an aggregated card after the action', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $first = InventoryLot::first();
    $second = InventoryLot::orderByDesc('id')->first();
    $this->postJson("/api/mobile/operational-modules/gudang-stok/records/{$first->id}/actions/update_lot", ['fields' => [
        'inventory_lot_id' => $second->id, 'location_name' => 'Rak Baru', 'storage_type' => 'dry', 'lot_status' => 'available',
    ]])->assertOk()->assertJsonPath('data.fields.3.value', '150,000')->assertJsonCount(2, 'data.sections.0.items');
    expect($first->refresh()->location_name)->toBe('Gudang Utama')->and($second->refresh()->location_name)->toBe('Rak Baru');
});

it('scopes the same ingredient separately by warehouse and rejects cross warehouse corrections', function () {
    ($this->receive)(100);
    $first = InventoryLot::first();
    $otherWarehouse = Warehouse::create(['sppg_unit_id' => $this->unit->id, 'code' => 'OTHER', 'name' => 'Gudang Pangan 2', 'type' => 'food', 'is_active' => true]);
    $other = $first->replicate();
    $other->warehouse_id = $otherWarehouse->id;
    $other->stock_receipt_item_id = null;
    $other->save();
    $movement = StockMovement::first()->replicate();
    $movement->warehouse_id = $otherWarehouse->id;
    $movement->inventory_lot_id = $other->id;
    $movement->save();
    expect(($this->cards)()->first()->balance_quantity)->toBe(100.0)
        ->and(app(WarehouseStockCardService::class)->cards($this->unit->id, $otherWarehouse->id)->first()->balance_quantity)->toBe(100.0);
    $this->postJson("/api/mobile/operational-modules/gudang-stok/records/{$first->id}/actions/adjust_stock", ['fields' => [
        'inventory_lot_id' => $other->id, 'actual_quantity' => 70, 'adjustment_type' => 'stock_opname', 'reason' => 'Hitung ulang',
    ]])->assertNotFound();
});

it('orders the ledger by movement date then creation time then id', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $first = StockMovement::first();
    $second = StockMovement::orderByDesc('id')->first();
    $first->forceFill(['created_at' => '2026-09-04 10:00:00'])->save();
    $second->forceFill(['created_at' => '2026-09-04 09:00:00'])->save();
    $ledger = app(WarehouseStockCardService::class)->ledger($this->unit->id, $this->warehouse->id, $this->ingredient->id);
    expect($ledger->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and((float) $ledger->first()->running_balance)->toBe(50.0);
});

it('renders the web lot and running balance detail', function () {
    ($this->receive)(100);
    ($this->receive)(50, 'LOT-B');
    $service = app(WarehouseStockCardService::class);
    $html = view('livewire.v3.warehouse.stock.detail', [
        'card' => ($this->cards)()->first(),
        'cardLots' => $service->lots($this->unit->id, $this->warehouse->id, $this->ingredient->id)->with('receiptItem.receipt')->get(),
        'ledger' => $service->ledger($this->unit->id, $this->warehouse->id, $this->ingredient->id),
        'types' => OperationsPresentation::movementTypes(),
    ])->render();
    expect($html)->toContain('Kartu Stok — BERAS', 'LOT-A', 'LOT-B', '150,000', 'Saldo berjalan');
});

it('does not truncate active lot totals at one hundred and never merges different units', function () {
    ($this->receive)(100);
    $lot = InventoryLot::first();
    for ($i = 0; $i < 101; $i++) {
        $copy = $lot->replicate();
        $copy->stock_receipt_item_id = null;
        $copy->save();
    }
    expect(($this->cards)()->first()->active_lot_count)->toBe(102)
        ->and(($this->cards)()->first()->balance_quantity)->toBe(100.0);
    // Lot state is not a second source of official balance; only recorded movements count.
    $otherUnit = SppgUnit::create(['code'=>'OTHER', 'name'=>'Other', 'is_active'=>true]);
    $foreign = StockMovement::first()->replicate();
    $foreign->sppg_unit_id = $otherUnit->id;
    $foreign->save();
    expect(($this->cards)()->first()->balance_quantity)->toBe(100.0);
});

it('keeps mobile and export access restricted to warehouse viewers', function () {
    ($this->receive)(100);
    $viewer = User::create(['name'=>'No access', 'email'=>'no-access@example.test', 'password'=>'password', 'is_active'=>true, 'is_super_admin'=>false]);
    $this->actingAs($viewer);
    Sanctum::actingAs($viewer, ['mobile']);
    $this->getJson('/api/mobile/operational-modules/gudang-stok/records')->assertForbidden();
    $this->get('/v3/gudang/stok/ekspor')->assertForbidden();
});
