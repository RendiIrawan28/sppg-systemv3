<?php

use App\Models\PortioningReturn;
use App\Models\PreparationReturn;
use App\Models\ProcessingReturn;
use App\Models\SppgUnit;
use App\Models\StockReceipt;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-REPORT', 'name' => 'SPPG Laporan Gudang',
        'slug' => 'sppg-laporan-gudang', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Admin Laporan Gudang', 'email' => 'gudang-report@example.test',
        'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
    ]);
    $this->warehouse = Warehouse::query()->create([
        'sppg_unit_id' => $this->unit->id, 'code' => 'FOOD',
        'name' => 'Gudang Pangan', 'type' => Warehouse::TYPE_FOOD, 'is_active' => true,
    ]);
    $this->nonFoodWarehouse = Warehouse::query()->create([
        'sppg_unit_id' => $this->unit->id, 'code' => 'NON_FOOD',
        'name' => 'Gudang Non-Pangan', 'type' => Warehouse::TYPE_NON_FOOD, 'is_active' => true,
    ]);
});

it('exports daily food receipts as a PDF on web and mobile', function (): void {
    $receipt = StockReceipt::query()->create([
        'sppg_unit_id' => $this->unit->id, 'warehouse_id' => $this->warehouse->id,
        'receipt_number' => 'PBM-REPORT-01', 'receipt_date' => '2026-10-09',
        'status' => StockReceipt::STATUS_RECEIVED,
    ]);
    $receipt->items()->create([
        'ingredient_name_snapshot' => 'Beras uji', 'unit_snapshot' => 'kg',
        'received_quantity' => 10, 'accepted_quantity' => 9, 'rejected_quantity' => 1,
    ]);

    $this->actingAs($this->user);
    $web = $this->get('/v3/gudang/ekspor/receipts?date=2026-10-09')->assertOk();
    expect($web->headers->get('content-type'))->toContain('application/pdf')
        ->and($web->headers->get('content-disposition'))->toContain('laporan-penerimaan-bahan-baku-09-10-2026.pdf')
        ->and(str_starts_with($web->getContent(), '%PDF-'))->toBeTrue();

    Sanctum::actingAs($this->user, ['mobile']);
    $mobile = $this->get('/api/mobile/warehouse-reports/receipts?date=2026-10-09')->assertOk();
    expect(str_starts_with($mobile->getContent(), '%PDF-'))->toBeTrue();
});

it('exports daily withdrawals and returns from all three divisions as a PDF', function (): void {
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $this->unit->id, 'warehouse_id' => $this->warehouse->id,
        'withdrawal_number' => 'PG/20261009/0001', 'withdrawal_date' => '2026-10-09',
        'division_code' => 'persiapan', 'status' => WarehouseWithdrawal::VERIFIED,
        'taken_by' => $this->user->id,
    ]);
    $withdrawal->items()->create([
        'ingredient_name_snapshot' => 'Beras uji', 'unit_snapshot' => 'kg',
        'requested_quantity' => 5, 'actual_quantity' => 5, 'taken_quantity_kg' => 5,
    ]);

    foreach ([
        PreparationReturn::class => ['preparation_session_id', 'preparation_session_item_id'],
        ProcessingReturn::class => ['processing_batch_id', 'processing_material_usage_id'],
        PortioningReturn::class => ['portioning_session_id', 'portioning_supply_id'],
    ] as $model => $references) {
        $model::query()->create([
            'sppg_unit_id' => $this->unit->id,
            $references[0] => 1, $references[1] => 1, 'ingredient_id' => 1,
            'return_number' => 'RT-'.class_basename($model),
            'return_date' => '2026-10-09', 'ingredient_name_snapshot' => 'Beras uji',
            'unit_snapshot' => 'kg', 'requested_quantity' => 2, 'actual_quantity' => 2,
            'condition_status' => 'good', 'warehouse_disposition' => 'available',
            'reason' => 'Sisa bahan', 'photo_path' => 'uji.jpg',
            'status' => $model::VERIFIED, 'returned_by' => $this->user->id,
            'submitted_at' => '2026-10-09 12:00:00',
        ]);
    }

    $this->actingAs($this->user);
    $response = $this->get('/v3/gudang/ekspor/withdrawals-returns?date=2026-10-09')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('laporan-pengambilan-retur-bahan-baku-09-10-2026.pdf')
        ->and(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();

    Sanctum::actingAs($this->user, ['mobile']);
    $this->get('/api/mobile/warehouse-reports/withdrawals-returns?date=2026-10-09')->assertOk();
});

it('rejects warehouse reports without stock viewing access and invalid dates', function (): void {
    $unprivileged = User::query()->create([
        'name' => 'Tanpa akses Gudang', 'email' => 'tanpa-gudang@example.test',
        'password' => 'password', 'is_active' => true,
    ]);

    $this->actingAs($unprivileged)
        ->get('/v3/gudang/ekspor/receipts?date=2026-10-09')->assertForbidden();
    Sanctum::actingAs($this->user, ['mobile']);
    $this->getJson('/api/mobile/warehouse-reports/receipts?date=09-10-2026')->assertUnprocessable();
    $this->get('/v3/gudang/ekspor/unknown?date=2026-10-09')->assertNotFound();
});
