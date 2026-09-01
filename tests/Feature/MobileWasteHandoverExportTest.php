<?php

use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WasteHandoverReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('exports every waste handover report type through the mobile document endpoint', function (string $division, string $module): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-MOBILE-WASTE',
        'name' => 'SPPG Mobile Limbah',
        'slug' => 'sppg-mobile-limbah',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Super Admin Mobile',
        'email' => "mobile-waste-{$division}@example.test",
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $report = WasteHandoverReport::query()->create([
        'sppg_unit_id' => $unit->id,
        'division_type' => $division,
        'report_date' => now()->toDateString(),
        'effective_date' => now()->toDateString(),
        'handed_over_at' => now(),
        'first_party_name' => 'Petugas SPPG',
        'first_party_position' => 'Petugas Divisi',
        'first_party_address' => 'SPPG Mobile Limbah',
        'second_party_name' => 'Penerima Limbah',
        'second_party_position' => 'Mitra',
        'second_party_address' => 'Alamat Mitra',
        'petugas_id' => $user->id,
        'petugas_name_snapshot' => $user->name,
        'status' => 'verified',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $report->items()->create([
        'waste_type' => 'Sisa bahan makanan',
        'quantity' => 2.5,
        'unit' => 'kg',
        'sort_order' => 1,
    ]);

    Sanctum::actingAs($user, ['mobile']);

    $response = $this->get("/api/mobile/operational-modules/{$module}/records/{$report->id}/document");

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
})->with([
    'persiapan' => ['preparation', 'ba-limbah-persiapan'],
    'pencucian' => ['washing', 'ba-limbah-pencucian'],
    'kebersihan' => ['cleaning', 'ba-limbah-kebersihan'],
]);
