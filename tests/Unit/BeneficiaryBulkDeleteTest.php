<?php

use App\Livewire\V3\Beneficiaries\Index;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->originalConnection = DB::getDefaultConnection();
    config([
        'database.connections.beneficiary_bulk_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ],
        'sppg.unit_id' => 1,
    ]);
    DB::purge('beneficiary_bulk_test');
    DB::setDefaultConnection('beneficiary_bulk_test');
    expect(DB::connection()->getConfig('database'))->toBe(':memory:');

    Schema::create('sppg_units', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('is_active');
    });
    Schema::create('beneficiaries', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('sppg_unit_id');
        $table->string('name');
        $table->boolean('is_active');
    });
    Schema::create('beneficiary_allergen', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('beneficiary_id');
    });
    DB::table('sppg_units')->insert(['id' => 1, 'name' => 'Unit pengujian', 'is_active' => true]);
});

afterEach(function () {
    DB::purge('beneficiary_bulk_test');
    DB::setDefaultConnection($this->originalConnection);
});

function bulkDeleteUser(bool $allowed): void
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_active = true;
    $user->is_super_admin = false;
    $user->shouldReceive('can')->andReturnUsing(fn ($permission) => $allowed && $permission === 'beneficiaries.delete');
    auth()->setUser($user);
}

it('selects only the visible page and clears selection when filters change', function () {
    bulkDeleteUser(true);
    for ($i = 1; $i <= 17; $i++) {
        DB::table('beneficiaries')->insert(['sppg_unit_id' => 1, 'name' => sprintf('P%02d', $i), 'is_active' => true]);
    }
    DB::table('beneficiaries')->insert(['sppg_unit_id' => 2, 'name' => 'Asing', 'is_active' => true]);

    $page = new Index;
    $page->togglePageSelection();
    expect($page->selectedBeneficiaryIds)->toHaveCount(15)
        ->not->toContain((string) DB::table('beneficiaries')->where('name', 'Asing')->value('id'));
    $page->togglePageSelection();
    expect($page->selectedBeneficiaryIds)->toBe([]);
    $page->togglePageSelection();
    $page->updatedSearch();
    expect($page->selectedBeneficiaryIds)->toBe([]);
});

it('deletes only selected recipients in the current unit and their allergen links', function () {
    bulkDeleteUser(true);
    $first = DB::table('beneficiaries')->insertGetId(['sppg_unit_id' => 1, 'name' => 'Pertama', 'is_active' => true]);
    $second = DB::table('beneficiaries')->insertGetId(['sppg_unit_id' => 1, 'name' => 'Kedua', 'is_active' => true]);
    $remaining = DB::table('beneficiaries')->insertGetId(['sppg_unit_id' => 1, 'name' => 'Tetap', 'is_active' => true]);
    DB::table('beneficiary_allergen')->insert([['beneficiary_id' => $first], ['beneficiary_id' => $remaining]]);

    $page = new Index;
    $page->selectedBeneficiaryIds = [(string) $first, (string) $second];
    $page->deleteSelected();

    expect(DB::table('beneficiaries')->pluck('id')->all())->toBe([$remaining])
        ->and(DB::table('beneficiary_allergen')->pluck('beneficiary_id')->all())->toBe([$remaining])
        ->and($page->selectedBeneficiaryIds)->toBe([]);
});

it('rejects foreign-unit selections atomically and blocks users without delete permission', function () {
    $own = DB::table('beneficiaries')->insertGetId(['sppg_unit_id' => 1, 'name' => 'Sendiri', 'is_active' => true]);
    $foreign = DB::table('beneficiaries')->insertGetId(['sppg_unit_id' => 2, 'name' => 'Asing', 'is_active' => true]);
    bulkDeleteUser(true);
    $page = new Index;
    $page->selectedBeneficiaryIds = [(string) $own, (string) $foreign];
    expect(fn () => $page->deleteSelected())->toThrow(ValidationException::class)
        ->and(DB::table('beneficiaries')->count())->toBe(2);

    bulkDeleteUser(false);
    $page->selectedBeneficiaryIds = [(string) $own];
    expect(fn () => $page->deleteSelected())->toThrow(HttpException::class)
        ->and(DB::table('beneficiaries')->count())->toBe(2);
});
