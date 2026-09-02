<?php

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\ProcessingBatch;
use App\Models\ProcessingMaterialStock;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use App\Services\ProcessingMaterialStockService;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function immediateProcessingWithdrawalContext(string $suffix): array
{
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-IMM-'.$suffix,
        'name' => 'SPPG Langsung '.$suffix,
        'slug' => 'sppg-langsung-'.strtolower($suffix),
        'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Petugas Langsung '.$suffix,
        'email' => 'langsung-'.strtolower($suffix).'@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg-imm-'.strtolower($suffix),
        'name' => 'Kilogram '.$suffix,
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'measurement_unit_id' => $measurementUnit->id,
        'code' => 'ING-IMM-'.$suffix,
        'name' => 'Bahan '.$suffix,
        'category' => 'vegetable',
        'edible_portion_percent' => 100,
        'loss_factor' => 1,
        'rounding_mode' => 'up',
        'nutrition_reference_grams' => 100,
        'is_active' => true,
    ]);
    $warehouse = Warehouse::forUnit($unit->id, Warehouse::TYPE_FOOD);
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_id' => $warehouse->id,
        'ingredient_id' => $ingredient->id,
        'unit_snapshot' => 'kg',
        'initial_quantity' => 10,
        'balance_quantity' => 10,
        'initial_quantity_kg' => 10,
        'balance_quantity_kg' => 10,
        'lot_number' => 'LOT-IMM-'.$suffix,
        'storage_type' => 'dry',
        'status' => InventoryLot::AVAILABLE,
    ]);
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu '.$suffix,
        'product_name' => 'Menu '.$suffix,
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
        'petugas_id' => $actor->id,
    ]);

    return compact('unit', 'actor', 'ingredient', 'lot', 'batch');
}

function submitImmediateProcessingWithdrawal(array $context, float $quantity = 6): WarehouseWithdrawal
{
    $service = app(WarehouseWithdrawalService::class);
    $withdrawal = $service->createMobileDraft(
        $context['unit']->id,
        'pengolahan',
        'record:'.$context['batch']->id,
        null,
        null,
        null,
        $context['actor'],
    );
    $service->createMobileDraftItem(
        $withdrawal,
        $context['lot']->id,
        $quantity,
        null,
        'tests/pengambilan-langsung.jpg',
        null,
        $context['actor'],
    );

    return $service->submitMobileDraft($withdrawal->refresh(), $context['actor']);
}

it('makes warehouse materials available to processing immediately and reconciles the verified quantity', function (): void {
    $context = immediateProcessingWithdrawalContext('RECONCILE');
    $withdrawal = submitImmediateProcessingWithdrawal($context, 6);
    $stock = ProcessingMaterialStock::query()->sole();

    expect($withdrawal->status)->toBe(WarehouseWithdrawal::WAITING)
        ->and((float) $context['lot']->refresh()->balance_quantity)->toBe(10.0)
        ->and((float) $stock->received_quantity)->toBe(6.0)
        ->and((float) $stock->available_quantity)->toBe(6.0)
        ->and($stock->notes)->toContain('langsung tersedia');

    app(ProcessingMaterialStockService::class)->syncBatchUsages(
        $context['batch'],
        [$stock->id => 4],
        $context['actor'],
    );
    $item = $withdrawal->items()->sole();
    app(WarehouseWithdrawalService::class)->verify(
        $withdrawal,
        $context['actor'],
        [$item->id => 5],
    );

    $stock->refresh();
    expect($withdrawal->refresh()->status)->toBe(WarehouseWithdrawal::VERIFIED)
        ->and((float) $context['lot']->refresh()->balance_quantity)->toBe(5.0)
        ->and((float) $stock->received_quantity)->toBe(5.0)
        ->and((float) $stock->available_quantity)->toBe(1.0)
        ->and($stock->notes)->toContain('diverifikasi Gudang')
        ->and(StockMovement::query()->where('source_id', $withdrawal->id)->count())->toBe(1);
});

it('does not allow warehouse verification below material already used by processing', function (): void {
    $context = immediateProcessingWithdrawalContext('USED');
    $withdrawal = submitImmediateProcessingWithdrawal($context, 6);
    $stock = ProcessingMaterialStock::query()->sole();
    app(ProcessingMaterialStockService::class)->syncBatchUsages(
        $context['batch'],
        [$stock->id => 4],
        $context['actor'],
    );
    $item = $withdrawal->items()->sole();

    expect(fn () => app(WarehouseWithdrawalService::class)->verify(
        $withdrawal,
        $context['actor'],
        [$item->id => 3],
    ))->toThrow(ValidationException::class);

    expect($withdrawal->refresh()->status)->toBe(WarehouseWithdrawal::WAITING)
        ->and((float) $context['lot']->refresh()->balance_quantity)->toBe(10.0)
        ->and((float) $stock->refresh()->received_quantity)->toBe(6.0)
        ->and((float) $stock->available_quantity)->toBe(2.0)
        ->and(StockMovement::query()->where('source_id', $withdrawal->id)->doesntExist())->toBeTrue();
});

it('removes an unused provisional processing stock when warehouse rejects the pickup', function (): void {
    $context = immediateProcessingWithdrawalContext('REJECT');
    $withdrawal = submitImmediateProcessingWithdrawal($context, 6);

    expect(ProcessingMaterialStock::query()->count())->toBe(1);
    app(WarehouseWithdrawalService::class)->reject(
        $withdrawal,
        $context['actor'],
        'Barang fisik tidak sesuai catatan.',
    );

    expect($withdrawal->refresh()->status)->toBe(WarehouseWithdrawal::REJECTED)
        ->and(ProcessingMaterialStock::query()->count())->toBe(0)
        ->and((float) $context['lot']->refresh()->balance_quantity)->toBe(10.0);
});
