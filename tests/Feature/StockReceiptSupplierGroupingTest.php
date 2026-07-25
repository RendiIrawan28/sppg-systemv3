<?php

use App\Livewire\V3\Warehouse\Receipts\Show;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\ProcurementRequest;
use App\Models\SppgUnit;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockReceiptService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('creates one stock receipt per supplier and stores one shipment photo', function (): void {
    Storage::fake('public');
    $unit = SppgUnit::query()->firstOrFail();
    $user = User::factory()->create([
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $request = orderedProcurementWithTwoSuppliers($unit, $user);
    $this->actingAs($user);

    $receipts = app(StockReceiptService::class)->createGroupedFromProcurementRequest($request);

    expect($receipts)->toHaveCount(2)
        ->and($receipts->pluck('supplier_id')->unique())->toHaveCount(2)
        ->and($receipts->every(fn (StockReceipt $receipt): bool => $receipt->items()->count() === 1))->toBeTrue();

    $receipt = $receipts->first();

    Livewire::actingAs($user)
        ->test(Show::class, ['receipt' => $receipt])
        ->set('documentation', UploadedFile::fake()->image('kiriman-supplier.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $receipt->refresh();
    expect($receipt->documentation_path)->not->toBeNull()
        ->and($receipt->received_by_name)->toBe($user->name)
        ->and($receipt->items()->firstOrFail()->quality_status)->toBe('accepted');
    Storage::disk('public')->assertExists($receipt->documentation_path);

    app(StockReceiptService::class)->receive($receipt);
    expect($receipt->fresh()->status)->toBe(StockReceipt::STATUS_RECEIVED);
});

it('requires a shipment photo before goods enter stock', function (): void {
    $unit = SppgUnit::query()->firstOrFail();
    $user = User::factory()->create([
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $request = orderedProcurementWithTwoSuppliers($unit, $user);
    $this->actingAs($user);
    $receipt = app(StockReceiptService::class)
        ->createGroupedFromProcurementRequest($request)
        ->firstOrFail();
    $receipt->items()->update(['quality_status' => 'accepted']);

    expect(fn () => app(StockReceiptService::class)->receive($receipt))
        ->toThrow(InvalidArgumentException::class, 'Satu foto dokumentasi kiriman supplier wajib diunggah.');
});

function orderedProcurementWithTwoSuppliers(SppgUnit $unit, User $user): ProcurementRequest
{
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg',
        'name' => 'Kilogram',
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $request = ProcurementRequest::query()->create([
        'sppg_unit_id' => $unit->id,
        'request_date' => today(),
        'needed_date' => today()->addDay(),
        'status' => ProcurementRequest::STATUS_ORDERED,
        'price_status' => 'finalized',
        'created_by' => $user->id,
        'ordered_by' => $user->id,
        'ordered_at' => now(),
    ]);

    foreach (['Supplier Sayur' => 'Wortel', 'Supplier Kering' => 'Beras'] as $supplierName => $ingredientName) {
        $supplier = Supplier::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => str($supplierName)->slug()->upper()->toString(),
            'name' => $supplierName,
            'is_active' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'sppg_unit_id' => $unit->id,
            'measurement_unit_id' => $measurementUnit->id,
            'code' => str($ingredientName)->slug()->upper()->toString(),
            'name' => $ingredientName,
            'is_active' => true,
        ]);
        $request->items()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'ingredient_code_snapshot' => $ingredient->code,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit_snapshot' => 'kg',
            'requested_quantity' => 10,
            'approved_quantity' => 10,
            'requested_quantity_kg' => 10,
            'approved_quantity_kg' => 10,
            'estimated_unit_price' => 10000,
            'estimated_total_price' => 100000,
        ]);
    }

    return $request;
}
