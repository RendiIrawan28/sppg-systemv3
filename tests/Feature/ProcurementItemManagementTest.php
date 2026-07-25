<?php

use App\Livewire\V3\Procurement\Show;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\ProcurementRequest;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows an editable procurement request to add and remove purchase items', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-TEST',
        'name' => 'SPPG Pengujian',
        'slug' => 'sppg-pengujian',
        'is_active' => true,
    ]);
    $user = User::factory()->create([
        'is_active' => true,
        'is_super_admin' => true,
    ]);
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
        'code' => 'BHN-001',
        'name' => 'Beras Tambahan',
        'reference_price' => 15000,
        'is_active' => true,
    ]);
    $request = ProcurementRequest::query()->create([
        'sppg_unit_id' => $unit->id,
        'request_date' => today(),
        'needed_date' => today()->addDay(),
        'status' => ProcurementRequest::STATUS_DRAFT,
        'price_status' => 'draft',
        'created_by' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Show::class, ['procurement' => $request])
        ->set('newIngredientId', (string) $ingredient->id)
        ->call('addItem')
        ->assertHasNoErrors()
        ->assertSet('newIngredientId', '');

    $item = $request->items()->firstOrFail();

    expect($request->fresh()->total_items)->toBe(1)
        ->and($item->ingredient_id)->toBe($ingredient->id)
        ->and((float) $item->requested_quantity)->toBe(1.0)
        ->and((float) $item->requested_quantity_kg)->toBe(1.0)
        ->and((float) $item->estimated_unit_price)->toBe(15000.0);

    $component
        ->call('removeItem', $item->id)
        ->assertHasNoErrors();

    expect($request->items()->count())->toBe(0)
        ->and($request->fresh()->total_items)->toBe(0)
        ->and((float) $request->fresh()->estimated_total_amount)->toBe(0.0);
});
