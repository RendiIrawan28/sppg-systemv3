<?php

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\OpeningStock;
use App\Models\SppgUnit;
use App\Models\StockAdjustment;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\User;
use App\Support\Mobile\MobileWorkspaceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-MOBILE-GUDANG', 'name' => 'SPPG Mobile Gudang', 'slug' => 'sppg-mobile-gudang', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Admin Mobile Gudang', 'email' => 'mobile-gudang@example.test', 'password' => 'password',
        'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->kilogram = MeasurementUnit::query()->create([
        'code' => 'kg', 'name' => 'Kilogram', 'symbol' => 'kg', 'unit_type' => 'weight',
        'to_base_factor' => 1000, 'is_active' => true,
    ]);
    $this->pieces = MeasurementUnit::query()->create([
        'code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'unit_type' => 'count',
        'to_base_factor' => 1, 'is_active' => true,
    ]);
    $this->liters = MeasurementUnit::query()->create([
        'code' => 'l', 'name' => 'Liter', 'symbol' => 'l', 'unit_type' => 'volume',
        'to_base_factor' => 1000, 'is_active' => true,
    ]);
    $this->ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->kilogram->id,
        'code' => 'BERAS-MOBILE', 'name' => 'Beras Mobile', 'category' => 'staple',
        'edible_portion_percent' => 100, 'loss_factor' => 1, 'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100, 'is_active' => true,
    ]);
    Sanctum::actingAs($this->user, ['mobile']);
});

it('creates multi-item opening stock from mobile and activates it immediately', function (): void {
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));
    $rows = [
        [
            'mode' => 'existing', 'ingredient_id' => $this->ingredient->id, 'new_name' => null,
            'new_category' => 'other', 'measurement_unit_id' => null, 'quantity' => '15.5',
            'lot_number' => 'LOT-MOBILE', 'expired_date' => null, 'storage_type' => 'dry',
            'location_name' => 'Rak Mobile', 'condition_notes' => 'Baik',
        ],
        [
            'mode' => 'new', 'ingredient_id' => null, 'new_name' => 'Minyak Mobile',
            'new_category' => 'oil', 'measurement_unit_id' => $this->liters->id, 'quantity' => '8.25',
            'lot_number' => 'LITER-MOBILE', 'expired_date' => null, 'storage_type' => 'dry',
            'location_name' => 'Rak Cairan', 'condition_notes' => null,
        ],
    ];

    $this->postJson('/api/mobile/operational-modules/gudang-stok-awal/records', [
        'fields' => [
            'opening_date' => today()->toDateString(),
            'notes' => 'Stok awal dari Android',
            'rows_payload' => json_encode($rows),
        ],
        'files' => ['photo_path' => $photo],
    ])->assertCreated()->assertJsonPath('data.status', 'active');

    $opening = OpeningStock::query()->with('items')->firstOrFail();
    expect($opening->items)->toHaveCount(2)
        ->and((float) InventoryLot::query()->where('unit_snapshot', 'kg')->value('balance_quantity'))->toBe(15.5)
        ->and((float) InventoryLot::query()->where('unit_snapshot', 'l')->value('balance_quantity'))->toBe(8.25)
        ->and((float) InventoryLot::query()->where('unit_snapshot', 'l')->value('balance_quantity_kg'))->toBe(0.0);
    Storage::disk('public')->assertExists($opening->photo_path);
});

it('keeps mobile receipt quantities and kilogram conversion identical to the website', function (): void {
    $receipt = StockReceipt::query()->create([
        'sppg_unit_id' => $this->unit->id, 'receipt_date' => today(), 'status' => StockReceipt::STATUS_DRAFT,
        'created_by' => $this->user->id,
    ]);
    $item = StockReceiptItem::query()->create([
        'stock_receipt_id' => $receipt->id, 'ingredient_id' => $this->ingredient->id,
        'ingredient_name_snapshot' => $this->ingredient->name, 'unit_snapshot' => 'karung',
        'ordered_quantity' => 10, 'received_quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0,
        'ordered_quantity_kg' => 250, 'received_quantity_kg' => 250, 'accepted_quantity_kg' => 250,
        'rejected_quantity_kg' => 0, 'quality_status' => 'accepted',
    ]);

    $this->putJson("/api/mobile/operational-modules/gudang/records/{$receipt->id}/relations/items/{$item->id}", [
        'fields' => [
            'received_quantity' => '8', 'accepted_quantity' => '7', 'rejected_quantity' => '1',
            'supplier_batch_number' => 'B-01', 'expired_date' => null,
            'received_temperature_celsius' => null, 'quality_notes' => 'Satu karung ditolak',
        ],
        'files' => [],
    ])->assertOk();

    $item->refresh();
    expect((float) $item->received_quantity_kg)->toBe(200.0)
        ->and((float) $item->accepted_quantity_kg)->toBe(175.0)
        ->and((float) $item->rejected_quantity_kg)->toBe(25.0)
        ->and($item->quality_status)->toBe('partial');

    $this->putJson("/api/mobile/operational-modules/gudang/records/{$receipt->id}/relations/items/{$item->id}", [
        'fields' => [
            'received_quantity' => '8', 'accepted_quantity' => '8', 'rejected_quantity' => '1',
            'supplier_batch_number' => null, 'expired_date' => null,
            'received_temperature_celsius' => null, 'quality_notes' => null,
        ],
        'files' => [],
    ])->assertUnprocessable();
});

it('creates mobile stock adjustment as draft and verifies it in a separate step', function (): void {
    $pieceIngredient = Ingredient::query()->create([
        'sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->pieces->id,
        'code' => 'BOX-MOBILE', 'name' => 'Kotak Mobile', 'category' => 'other',
        'edible_portion_percent' => 100, 'loss_factor' => 1, 'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100, 'is_active' => true,
    ]);
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $this->unit->id, 'ingredient_id' => $pieceIngredient->id,
        'unit_snapshot' => 'pcs', 'initial_quantity' => 20, 'balance_quantity' => 20,
        'initial_quantity_kg' => 0, 'balance_quantity_kg' => 0,
        'lot_number' => 'LOT-ADJ', 'location_name' => 'Gudang Utama', 'storage_type' => 'dry', 'status' => 'available',
    ]);

    $this->postJson("/api/mobile/operational-modules/gudang-stok/records/{$lot->id}/actions/adjust_stock", [
        'fields' => ['actual_quantity' => '18', 'adjustment_type' => 'stock_opname', 'reason' => 'Hasil hitung fisik'],
        'files' => [],
    ])->assertOk();

    $adjustment = StockAdjustment::query()->firstOrFail();
    expect($adjustment->status)->toBe(StockAdjustment::DRAFT)
        ->and((float) $lot->refresh()->balance_quantity)->toBe(20.0);

    $this->postJson("/api/mobile/operational-modules/gudang-penyesuaian/records/{$adjustment->id}/actions/verify", [
        'fields' => [], 'files' => [],
    ])->assertOk();

    expect($adjustment->refresh()->status)->toBe(StockAdjustment::VERIFIED)
        ->and((float) $lot->refresh()->balance_quantity)->toBe(18.0)
        ->and((float) $lot->balance_quantity_kg)->toBe(0.0);
});

it('shows the ingredient name even when it is outside the capped option list', function (): void {
    $now = now();
    Ingredient::query()->insert(collect(range(1, 251))->map(fn (int $index): array => [
        'sppg_unit_id' => $this->unit->id,
        'measurement_unit_id' => $this->pieces->id,
        'code' => sprintf('AAA-%03d', $index),
        'name' => sprintf('AAA Barang %03d', $index),
        'category' => 'other',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());
    $target = Ingredient::query()->create([
        'sppg_unit_id' => $this->unit->id, 'measurement_unit_id' => $this->pieces->id,
        'code' => 'ZZZ-TARGET', 'name' => 'ZZZ Bahan Tetap Tampil', 'category' => 'other',
        'edible_portion_percent' => 100, 'loss_factor' => 1, 'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100, 'is_active' => true,
    ]);
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $this->unit->id, 'ingredient_id' => $target->id,
        'unit_snapshot' => 'pcs', 'initial_quantity' => 10, 'balance_quantity' => 10,
        'initial_quantity_kg' => 0, 'balance_quantity_kg' => 0,
        'lot_number' => 'LOT-ZZZ', 'location_name' => 'Rak Z', 'storage_type' => 'dry', 'status' => 'available',
    ]);

    expect(app(MobileWorkspaceRegistry::class)
        ->options('ingredients', $this->unit->id))->not->toHaveKey((string) $target->id);

    $this->getJson("/api/mobile/operational-modules/gudang-stok/records/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'ZZZ Bahan Tetap Tampil')
        ->assertJsonFragment([
            'key' => 'ingredient_id',
            'label' => 'Bahan',
            'value' => 'ZZZ Bahan Tetap Tampil',
        ]);
});
