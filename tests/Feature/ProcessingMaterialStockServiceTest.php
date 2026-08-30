<?php

use App\Models\ProcessingBatch;
use App\Models\ProcessingMaterialStock;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\ProcessingMaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('uses one global processing stock across multiple batches and never allows a negative balance', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-STOCK-PRO',
        'name' => 'SPPG Stok Pengolahan',
        'slug' => 'sppg-stok-pengolahan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Pengolahan',
        'email' => 'stok-pengolahan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);

    $stock = ProcessingMaterialStock::query()->create([
        'sppg_unit_id' => $unit->id,
        'source_type' => 'warehouse',
        'source_id' => 100,
        'source_item_id' => 100,
        'material_name' => 'Beras',
        'unit_name' => 'kg',
        'received_quantity' => 100,
        'available_quantity' => 100,
        'source_reference' => 'PG/TEST/0001',
        'received_by' => $user->id,
        'received_at' => now(),
        'status' => ProcessingMaterialStock::AVAILABLE,
    ]);

    $batchA = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'product_name' => 'Batch A',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
    ]);
    $batchB = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'product_name' => 'Batch B',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
    ]);
    $batchC = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'product_name' => 'Batch C',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
    ]);

    $service = app(ProcessingMaterialStockService::class);
    $service->syncBatchUsages($batchA, [$stock->id => 60], $user);
    expect((float) $stock->refresh()->available_quantity)->toBe(40.0);

    $service->syncBatchUsages($batchB, [$stock->id => 30], $user);
    expect((float) $stock->refresh()->available_quantity)->toBe(10.0)
        ->and((float) $batchA->materialUsages()->sole()->quantity)->toBe(60.0)
        ->and((float) $batchB->materialUsages()->sole()->quantity)->toBe(30.0);

    expect(fn () => $service->syncBatchUsages($batchC, [$stock->id => 20], $user))
        ->toThrow(ValidationException::class);

    expect((float) $stock->refresh()->available_quantity)->toBe(10.0)
        ->and($batchC->materialUsages()->count())->toBe(0);
});

it('restores the previous batch usage before saving an edited quantity', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-EDIT-STOCK',
        'name' => 'SPPG Edit Stok',
        'slug' => 'sppg-edit-stok',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Edit Stok',
        'email' => 'edit-stok@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $stock = ProcessingMaterialStock::query()->create([
        'sppg_unit_id' => $unit->id,
        'source_type' => 'preparation',
        'source_id' => 200,
        'source_item_id' => 200,
        'material_name' => 'Wortel Bersih',
        'unit_name' => 'kg',
        'received_quantity' => 25,
        'available_quantity' => 25,
        'received_by' => $user->id,
        'received_at' => now(),
        'status' => ProcessingMaterialStock::AVAILABLE,
    ]);
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'product_name' => 'Sup Sayur',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
    ]);

    $service = app(ProcessingMaterialStockService::class);
    $service->syncBatchUsages($batch, [$stock->id => 20], $user);
    expect((float) $stock->refresh()->available_quantity)->toBe(5.0);

    $service->syncBatchUsages($batch, [$stock->id => 15], $user);
    expect((float) $stock->refresh()->available_quantity)->toBe(10.0)
        ->and((float) $batch->materialUsages()->sole()->quantity)->toBe(15.0);
});
