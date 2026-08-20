<?php

use App\Enums\PortioningSessionState;
use App\Livewire\V3\Portioning\Index;
use App\Models\FieldDistributionPlan;
use App\Models\PortioningSession;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\FieldOperationalPlanGenerator;
use App\Services\PortioningWorkflow;
use App\Services\PreparationOutputService;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function portioningTestContext(string $suffix = 'MAIN'): array
{
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-POR-'.$suffix,
        'name' => 'SPPG Pemorsian '.$suffix,
        'slug' => 'sppg-pemorsian-'.strtolower($suffix),
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Pemorsian '.$suffix,
        'email' => 'pemorsian-'.strtolower($suffix).'@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'plan_number' => 'RDL/POR/'.$suffix,
        'plan_year' => 2026,
        'sequence_number' => random_int(10, 9999),
        'distribution_date' => today(),
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Pemorsian',
        'planned_beneficiaries' => 100,
        'confirmed_beneficiaries' => 100,
        'planned_small_portions' => 60,
        'planned_large_portions' => 40,
        'planned_total_portions' => 100,
        'destination_count' => 1,
        'status' => 'activated',
        'created_by' => $user->id,
    ]);
    $plan->destinations()->create([
        'destination_type' => 'school',
        'destination_name_snapshot' => 'Sekolah Uji',
        'route_name' => 'Rute 1',
        'sequence_order' => 1,
        'registered_beneficiaries' => 100,
        'confirmed_beneficiaries' => 100,
        'small_portions' => 60,
        'large_portions' => 40,
        'confirmation_status' => 'confirmed',
    ]);

    return [$unit, $user, $plan->refresh()];
}

it('starts mobile portioning from an active distribution plan before taking materials', function (): void {
    [$unit, $user, $plan] = portioningTestContext('START');
    Sanctum::actingAs($user, ['mobile']);

    $this->postJson('/api/mobile/operational-modules/pemorsian/records', [
        'fields' => ['field_distribution_plan_id' => $plan->id],
    ])->assertCreated()
        ->assertJsonPath('data.state', 'in_progress');

    $session = PortioningSession::query()->sole();
    expect($session->field_distribution_plan_id)->toBe($plan->id)
        ->and($session->supplies()->count())->toBe(0)
        ->and($session->target_small_portions)->toBe(60)
        ->and($session->target_large_portions)->toBe(40)
        ->and($session->started_at)->not->toBeNull();

    $session->routeRecords()->create([
        'route_name' => 'Rute 1',
        'small_portions' => 25,
        'large_portions' => 15,
        'photo_path' => 'portioning/routes/route-1.jpg',
        'completed_at' => now(),
        'created_by' => $user->id,
    ]);
    $session->recalculateTotals();

    $detail = $this->getJson("/api/mobile/operational-modules/pemorsian/records/{$session->id}")
        ->assertOk()
        ->assertJsonPath('data.is_history', false);
    $fields = collect($detail->json('data.fields'));
    $routes = collect($detail->json('data.sections'))->firstWhere('key', 'routeRecords');
    expect($fields->firstWhere('key', 'actual_small_portions')['value'])->toBe('25')
        ->and($fields->firstWhere('key', 'actual_large_portions')['value'])->toBe('15')
        ->and($routes['title'])->toBe('Ompreng yang sudah diporsikan per rute')
        ->and($routes['items'])->toHaveCount(1);

    $moduleResponse = $this->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonPath('daily_summary.beneficiaries', 100)
        ->assertJsonPath('daily_summary.portions', 100)
        ->assertJsonPath('daily_summary.destinations', 1);
    $modules = collect($moduleResponse->json('data'));
    $withdrawalModule = $modules->firstWhere('slug', 'pengambilan-gudang-pemorsian');
    $referenceField = collect($withdrawalModule['form_fields'])->firstWhere('key', 'reference_selection');
    expect($referenceField['type'])->toBe('select')
        ->and($referenceField['options'])->toHaveKey('record:'.$session->id);

    $sameSession = app(FieldOperationalPlanGenerator::class)->generatePortioningSession($plan, $user);
    expect($sameSession->id)->toBe($session->id)
        ->and(PortioningSession::query()->count())->toBe(1);
});

it('starts the same portioning-first flow from the website', function (): void {
    [, $user, $plan] = portioningTestContext('WEB');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('productionPlanId', (string) $plan->id)
        ->call('createFromProductionPlan')
        ->assertHasNoErrors()
        ->assertSet('selectedId', PortioningSession::query()->sole()->id);

    expect(PortioningSession::query()->sole()->state)->toBe(PortioningSessionState::InProgress);
});

it('blocks warehouse pickup before start and allows cancellation only while portioning is empty', function (): void {
    [$unit, $user, $plan] = portioningTestContext('CANCEL');
    $session = app(FieldOperationalPlanGenerator::class)->generatePortioningSession($plan, $user);

    expect(fn () => app(WarehouseWithdrawalService::class)->createMobileDraft(
        $unit->id,
        'pemorsian',
        'record:'.$session->id,
        null,
        null,
        null,
        $user,
    ))->toThrow(ValidationException::class);

    $session = app(PortioningWorkflow::class)->start($session, $user);
    $cancelled = app(PortioningWorkflow::class)->cancel($session, $user, 'Salah memilih rencana');

    expect($cancelled->state)->toBe(PortioningSessionState::Cancelled)
        ->and($cancelled->notes)->toContain('Salah memilih rencana');
});

it('shows preparation output immediately in the active portioning session before verification', function (): void {
    [$unit, $user, $plan] = portioningTestContext('PREP');
    $session = app(PortioningWorkflow::class)->start(
        app(FieldOperationalPlanGenerator::class)->generatePortioningSession($plan, $user),
        $user,
    );
    $warehouseWithdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $user->id,
    ]);
    $preparation = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $warehouseWithdrawal->id,
        'session_number' => 'PS/POR/PREP',
        'preparation_date' => today(),
        'state' => 'completed',
        'status' => 'draft',
        'petugas_id' => $user->id,
    ]);
    $output = PreparationOutput::query()->create([
        'sppg_unit_id' => $unit->id,
        'preparation_session_id' => $preparation->id,
        'output_name' => 'Buah Potong',
        'quantity' => 20,
        'available_quantity' => 20,
        'unit_snapshot' => 'pack',
        'target_division' => 'portioning',
        'state' => PreparationOutput::AVAILABLE,
        'created_by' => $user->id,
    ]);

    $taken = app(PreparationOutputService::class)->requestWithdrawal($output, $user, [
        'destination_division' => 'portioning',
        'portioning_session_id' => $session->id,
        'requested_quantity' => 10,
    ]);
    expect($taken->status)->toBe(PreparationOutputWithdrawal::WAITING)
        ->and((float) $output->refresh()->available_quantity)->toBe(10.0)
        ->and(app(PortioningWorkflow::class)->canCancel($session))->toBeFalse();

    Sanctum::actingAs($user, ['mobile']);
    $section = collect($this->getJson("/api/mobile/operational-modules/pemorsian/records/{$session->id}")
        ->assertOk()
        ->json('data.sections'))
        ->firstWhere('key', 'preparationOutputWithdrawals');

    expect($section['items'])->toHaveCount(1)
        ->and($section['items'][0]['title'])->toBe('Buah Potong')
        ->and(collect($section['items'][0]['fields'])->firstWhere('key', 'verification_status_label')['value'])
        ->toBe('Menunggu pengecekan');
});

it('requires a warehouse or preparation material before completing portioning', function (): void {
    [, $user, $plan] = portioningTestContext('COMPLETE');
    $session = app(PortioningWorkflow::class)->start(
        app(FieldOperationalPlanGenerator::class)->generatePortioningSession($plan, $user),
        $user,
    );
    $session->routeRecords()->create([
        'route_name' => 'Rute 1',
        'small_portions' => 60,
        'large_portions' => 40,
        'photo_path' => 'portioning/routes/test.jpg',
        'completed_at' => now(),
        'created_by' => $user->id,
    ]);
    $session->update(['leftover_mode' => 'none']);

    expect(fn () => app(PortioningWorkflow::class)->complete($session, $user))
        ->toThrow(ValidationException::class);

    $session->supplies()->create([
        'source_type' => 'warehouse_withdrawal',
        'source_id' => 1,
        'source_item_id' => 1,
        'supply_name' => 'Ompreng',
        'quantity' => 100,
        'unit_name' => 'pcs',
        'received_by' => $user->id,
        'received_at' => now(),
    ]);

    expect(app(PortioningWorkflow::class)->complete($session, $user)->state)
        ->toBe(PortioningSessionState::Completed);
});

it('shows direct leftover choices and opens leftover entry only when food remains', function (): void {
    [, $user, $plan] = portioningTestContext('LEFTOVER');
    $session = app(PortioningWorkflow::class)->start(
        app(FieldOperationalPlanGenerator::class)->generatePortioningSession($plan, $user),
        $user,
    );
    Sanctum::actingAs($user, ['mobile']);

    $detail = $this->getJson("/api/mobile/operational-modules/pemorsian/records/{$session->id}")
        ->assertOk();
    expect(collect($detail->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('set_leftover_none', 'set_leftover_present');
    expect(collect($detail->json('data.sections'))->firstWhere('key', 'leftoverRecords')['can_create'])
        ->toBeFalse();

    $present = $this->postJson("/api/mobile/operational-modules/pemorsian/records/{$session->id}/actions/set_leftover_present", [
        'fields' => [],
    ])->assertOk();
    expect($present->json('data.fields'))
        ->and(collect($present->json('data.fields'))->firstWhere('key', 'leftover_mode')['value'])
        ->toBe('Ada sisa makanan');
    expect(collect($present->json('data.sections'))->firstWhere('key', 'leftoverRecords')['can_create'])
        ->toBeTrue();

    $none = $this->postJson("/api/mobile/operational-modules/pemorsian/records/{$session->id}/actions/set_leftover_none", [
        'fields' => [],
    ])->assertOk();
    expect(collect($none->json('data.fields'))->firstWhere('key', 'leftover_mode')['value'])
        ->toBe('Tidak ada sisa makanan');
    expect(collect($none->json('data.sections'))->firstWhere('key', 'leftoverRecords')['can_create'])
        ->toBeFalse();
});
