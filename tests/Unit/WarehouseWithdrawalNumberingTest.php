<?php

use App\Models\WarehouseWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    config(['database.connections.withdrawal_number_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('withdrawal_number_test');
    DB::setDefaultConnection('withdrawal_number_test');

    expect(DB::connection()->getDatabaseName())->toBe(':memory:');

    Schema::create('warehouse_withdrawals', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->unsignedBigInteger('sppg_unit_id');
        $table->string('withdrawal_number');
        $table->date('withdrawal_date');
        $table->timestamps();
        $table->unique(['sppg_unit_id', 'withdrawal_number']);
    });
});

it('continues after the highest number even when an earlier number is missing', function (): void {
    WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 1,
        'withdrawal_date' => '2026-09-29',
        'withdrawal_number' => 'PG/20260929/0001',
    ]);
    WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 1,
        'withdrawal_date' => '2026-09-29',
        'withdrawal_number' => 'PG/20260929/0003',
    ]);

    $next = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 1,
        'withdrawal_date' => '2026-09-29',
    ]);

    expect($next->withdrawal_number)->toBe('PG/20260929/0004');
});

it('uses the number date and unit rather than counting rows for the selected date', function (): void {
    WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 1,
        'withdrawal_date' => '2026-09-30',
        'withdrawal_number' => 'PG/20260929/0005',
    ]);

    $sameUnit = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 1,
        'withdrawal_date' => '2026-09-29',
    ]);
    $otherUnit = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => 2,
        'withdrawal_date' => '2026-09-29',
    ]);

    expect($sameUnit->withdrawal_number)->toBe('PG/20260929/0006')
        ->and($otherUnit->withdrawal_number)->toBe('PG/20260929/0001');
});
