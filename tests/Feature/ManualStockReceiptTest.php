<?php

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockReceiptService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedStockCardDatabase;

uses(IsolatedStockCardDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-MANUAL', 'name' => 'SPPG Manual', 'slug' => 'sppg-manual', 'is_active' => true,
    ]);
    $this->actor = User::query()->create([
        'name' => 'Petugas Gudang', 'email' => 'gudang-manual@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->actingAs($this->actor);
    $this->unitPcs = MeasurementUnit::query()->create([
        'code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'unit_type' => 'count',
        'to_base_factor' => 1, 'is_active' => true,
    ]);
    $this->ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->unitPcs->id,
        'code' => 'TELUR-MANUAL', 'name' => 'Telur Manual', 'category' => 'animal_protein',
        'grams_per_unit' => 60, 'edible_portion_percent' => 100, 'loss_factor' => 1,
        'rounding_mode' => 'up', 'nutrition_reference_grams' => 100, 'is_active' => true,
    ]);
    $this->supplier = Supplier::query()->create([
        'sppg_unit_id' => $this->unit->id, 'code' => 'SUP-MANUAL',
        'name' => 'Supplier Manual', 'is_active' => true,
    ]);
    $this->warehouse = Warehouse::forUnit($this->unit->id, Warehouse::TYPE_FOOD);
});

it('creates a traceable manual receipt and only adds accepted quantity to stock', function (): void {
    $service = app(StockReceiptService::class);
    $receipt = $service->createManual(
        $this->unit->id,
        $this->warehouse->id,
        $this->supplier->id,
        today()->toDateString(),
        'Penerimaan mendadak',
        [[
            'ingredient_id' => $this->ingredient->id,
            'received_quantity' => 100,
            'accepted_quantity' => 90,
            'rejected_quantity' => 10,
            'supplier_batch_number' => 'TELUR-001',
            'quality_notes' => 'Sepuluh butir retak',
        ]],
        $this->actor,
    );

    expect($receipt->procurement_request_id)->toBeNull()
        ->and($receipt->status)->toBe(StockReceipt::STATUS_DRAFT)
        ->and((float) $receipt->items->first()->accepted_quantity_kg)->toBe(5.4)
        ->and((float) $receipt->items->first()->rejected_quantity_kg)->toBe(0.6);

    $item = $receipt->items->first();
    $path = 'stock-receipts/test/telur.jpg';
    Storage::disk('public')->put($path, 'image');
    $item->photos()->create([
        'stock_receipt_id' => $receipt->id,
        'item_name_snapshot' => $item->ingredient_name_snapshot,
        'photo_path' => $path,
        'original_name' => 'telur.jpg',
        'uploaded_by' => $this->actor->id,
    ]);

    $service->receive($receipt);

    $lot = InventoryLot::query()->where('stock_receipt_item_id', $item->id)->firstOrFail();
    expect((float) $lot->balance_quantity)->toBe(90.0)
        ->and((float) $lot->balance_quantity_kg)->toBe(5.4)
        ->and((float) StockMovement::query()->where('source_id', $receipt->id)->value('quantity_in'))->toBe(90.0);
});

it('rejects manual receipt rows whose qc totals do not reconcile', function (): void {
    app(StockReceiptService::class)->createManual(
        $this->unit->id,
        $this->warehouse->id,
        $this->supplier->id,
        today()->toDateString(),
        null,
        [[
            'ingredient_id' => $this->ingredient->id,
            'received_quantity' => 10,
            'accepted_quantity' => 8,
            'rejected_quantity' => 1,
        ]],
        $this->actor,
    );
})->throws(ValidationException::class);
