<?php

use App\Enums\SecurityShiftStatus;
use App\Models\InventoryLot;
use App\Models\SecurityReport;
use App\Models\SecurityShift;
use App\Models\SppgUnit;
use App\Models\StockMovement;
use App\Models\TestDataCleanupLog;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\TestDataCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-CLEANUP',
        'name' => 'SPPG Cleanup',
        'slug' => 'sppg-cleanup',
        'is_active' => true,
    ]);
    $this->superAdmin = User::query()->create([
        'name' => 'Super Admin Cleanup',
        'email' => 'super-cleanup@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $this->regularUser = User::query()->create([
        'name' => 'Pengguna Biasa',
        'email' => 'regular-cleanup@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
});

it('shows the cleanup page only to super admin', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('v3.administration.test-data-cleanup'))
        ->assertOk()
        ->assertSee('Pembersihan Data Uji');

    $this->actingAs($this->regularUser)
        ->get(route('v3.administration.test-data-cleanup'))
        ->assertForbidden();
});

it('force deletes a locked security shift with reports and keeps an audit log', function (): void {
    $shift = SecurityShift::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'officer_id' => $this->regularUser->id,
        'officer_name_snapshot' => $this->regularUser->name,
        'started_at' => now()->subHours(12),
        'scheduled_end_at' => now(),
        'completed_at' => now(),
        'status' => SecurityShiftStatus::Completed,
        'created_by' => $this->regularUser->id,
    ]);
    $photoPath = 'mobile/keamanan/reports/cleanup.jpg';
    Storage::disk('public')->put($photoPath, 'photo');
    SecurityReport::query()->create([
        'security_shift_id' => $shift->id,
        'sppg_unit_id' => $this->unit->id,
        'sequence_number' => 1,
        'due_at' => now(),
        'reported_at' => now(),
        'situation' => 'safe',
        'gate_secure' => true,
        'perimeter_secure' => true,
        'photo_path' => $photoPath,
        'created_by' => $this->regularUser->id,
    ]);

    $counts = app(TestDataCleanupService::class)->purge(
        'security-shifts',
        $shift->id,
        $this->unit->id,
        $this->superAdmin,
        'Data shift dibuat khusus untuk pengujian.',
    );

    expect(SecurityShift::query()->find($shift->id))->toBeNull()
        ->and(SecurityReport::query()->where('security_shift_id', $shift->id)->exists())->toBeFalse()
        ->and($counts['security_shifts'])->toBe(1)
        ->and($counts['security_reports'])->toBe(1)
        ->and(TestDataCleanupLog::query()->where('source_id', $shift->id)->exists())->toBeTrue();
    Storage::disk('public')->assertMissing($photoPath);
});

it('removes stock movement from a locked withdrawal and recalculates the lot balance', function (): void {
    $lot = InventoryLot::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'unit_snapshot' => 'kg',
        'initial_quantity' => 100,
        'balance_quantity' => 90,
        'initial_quantity_kg' => 100,
        'balance_quantity_kg' => 90,
        'status' => InventoryLot::AVAILABLE,
    ]);
    StockMovement::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'inventory_lot_id' => $lot->id,
        'unit_snapshot' => 'kg',
        'movement_type' => StockMovement::TYPE_RECEIPT,
        'movement_date' => today(),
        'quantity_in' => 100,
        'quantity_out' => 0,
        'quantity_in_kg' => 100,
        'quantity_out_kg' => 0,
        'source_type' => 'seed',
        'source_id' => 1,
        'created_by' => $this->superAdmin->id,
    ]);
    $withdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'withdrawal_number' => 'WG-TEST-001',
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::VERIFIED,
        'taken_by' => $this->regularUser->id,
        'taken_by_name' => $this->regularUser->name,
        'created_by' => $this->regularUser->id,
    ]);
    $withdrawal->items()->create([
        'inventory_lot_id' => $lot->id,
        'ingredient_name_snapshot' => 'Bahan Uji',
        'unit_snapshot' => 'kg',
        'requested_quantity' => 10,
        'actual_quantity' => 10,
        'taken_quantity_kg' => 10,
        'verified_quantity_kg' => 10,
    ]);
    StockMovement::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'inventory_lot_id' => $lot->id,
        'unit_snapshot' => 'kg',
        'movement_type' => StockMovement::TYPE_HANDOVER,
        'movement_date' => today(),
        'quantity_in' => 0,
        'quantity_out' => 10,
        'quantity_in_kg' => 0,
        'quantity_out_kg' => 10,
        'source_type' => WarehouseWithdrawal::class,
        'source_id' => $withdrawal->id,
        'created_by' => $this->superAdmin->id,
    ]);

    app(TestDataCleanupService::class)->purge(
        'warehouse-withdrawals',
        $withdrawal->id,
        $this->unit->id,
        $this->superAdmin,
        'Pengambilan uji tercatat dengan jumlah yang salah.',
    );

    expect(WarehouseWithdrawal::query()->find($withdrawal->id))->toBeNull()
        ->and(StockMovement::query()->where('source_type', WarehouseWithdrawal::class)->where('source_id', $withdrawal->id)->exists())->toBeFalse()
        ->and((float) $lot->refresh()->balance_quantity)->toBe(100.0);
});

it('rejects cleanup requests from non super admin', function (): void {
    $shift = SecurityShift::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'officer_id' => $this->regularUser->id,
        'officer_name_snapshot' => $this->regularUser->name,
        'started_at' => now(),
        'scheduled_end_at' => now()->addHours(12),
        'status' => SecurityShiftStatus::Active,
    ]);

    app(TestDataCleanupService::class)->purge(
        'security-shifts',
        $shift->id,
        $this->unit->id,
        $this->regularUser,
        'Tidak boleh dijalankan pengguna biasa.',
    );
})->throws(HttpException::class);
