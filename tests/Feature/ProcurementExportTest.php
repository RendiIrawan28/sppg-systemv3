<?php

use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\ProcurementRequest;
use App\Models\SppgUnit;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('exports an approved procurement request as pdf and excel', function (): void {
    $unit = SppgUnit::query()->firstOrFail();
    $head = User::factory()->create([
        'name' => 'Kepala SPPG Pengujian',
        'is_active' => true,
    ]);
    $head->syncRoles([UserRole::KepalaSppg->value]);
    $procurement = approvedProcurementForExport($unit, $head);

    $this->actingAs($head)
        ->get(route('procurement-requests.pdf', $procurement))
        ->assertOk()
        ->assertDownload('pengadaan-bahan-PB-2026-0001.pdf');

    $this->actingAs($head)
        ->get(route('procurement-requests.excel', $procurement))
        ->assertOk()
        ->assertDownload('pengadaan-bahan-PB-2026-0001.xlsx');
});

it('does not export procurement before head approval', function (): void {
    $unit = SppgUnit::query()->firstOrFail();
    $head = User::factory()->create([
        'is_active' => true,
    ]);
    $head->syncRoles([UserRole::KepalaSppg->value]);
    $procurement = approvedProcurementForExport($unit, $head);
    $procurement->update([
        'status' => ProcurementRequest::STATUS_FINANCE_VERIFIED,
        'price_status' => 'finance_verified',
    ]);

    $this->actingAs($head)
        ->get(route('procurement-requests.pdf', $procurement))
        ->assertNotFound();

    $this->actingAs($head)
        ->get(route('procurement-requests.excel', $procurement))
        ->assertNotFound();
});

function approvedProcurementForExport(SppgUnit $unit, User $head): ProcurementRequest
{
    $measurementUnit = MeasurementUnit::query()->create([
        'code' => 'kg',
        'name' => 'Kilogram',
        'symbol' => 'kg',
        'unit_type' => 'weight',
        'to_base_factor' => 1000,
        'is_active' => true,
    ]);
    $ingredient = Ingredient::query()->create([
        'sppg_unit_id' => $unit->id,
        'measurement_unit_id' => $measurementUnit->id,
        'code' => 'BHN-BERAS',
        'name' => 'Beras',
        'reference_price' => 15000,
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'sppg_unit_id' => $unit->id,
        'code' => 'SUP-001',
        'name' => 'Supplier Pengujian',
        'is_active' => true,
    ]);
    $procurement = ProcurementRequest::query()->create([
        'sppg_unit_id' => $unit->id,
        'request_number' => 'PB/2026/0001',
        'request_date' => '2026-07-25',
        'needed_date' => '2026-07-28',
        'status' => ProcurementRequest::STATUS_APPROVED,
        'price_status' => 'finalized',
        'price_finalized_by' => $head->id,
        'price_finalized_at' => now(),
        'total_items' => 1,
        'estimated_total_amount' => 150000,
        'created_by' => $head->id,
    ]);
    $procurement->items()->create([
        'ingredient_id' => $ingredient->id,
        'supplier_id' => $supplier->id,
        'ingredient_code_snapshot' => $ingredient->code,
        'ingredient_name_snapshot' => $ingredient->name,
        'unit_snapshot' => 'kg',
        'requested_quantity' => 10,
        'approved_quantity' => 10,
        'requested_quantity_kg' => 10,
        'approved_quantity_kg' => 10,
        'estimated_unit_price' => 15000,
        'estimated_total_price' => 150000,
    ]);

    return $procurement;
}
