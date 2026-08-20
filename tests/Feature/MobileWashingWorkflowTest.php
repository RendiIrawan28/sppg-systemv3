<?php

use App\Enums\UserRole;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mobileWashingActor(string $name = 'Petugas Pencucian'): User
{
    $role = Role::findOrCreate(UserRole::PetugasPencucian->value, 'web');
    foreach (['washing.view', 'washing.update', 'washing.submit', 'washing.export'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::query()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.uniqid().'@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function mobileWashingSession(SppgUnit $unit, array $values = []): WashingSession
{
    $session = WashingSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'washing_date' => today(),
        'menu_name_snapshot' => 'Menu Uji Pencucian',
        'distribution_expected_containers' => 30,
        'distribution_returned_containers' => 30,
        'distribution_damaged_containers' => 0,
        'expected_containers' => 30,
        'state' => $values['state'] ?? 'planned',
        'status' => $values['status'] ?? 'draft',
    ]);
    $session->checklistItems()->createMany([
        ['checklist_key' => 'scrape', 'label' => 'Sisa makanan dibuang', 'is_mandatory' => true, 'sort_order' => 1],
        ['checklist_key' => 'wash', 'label' => 'Ompreng dicuci bersih', 'is_mandatory' => true, 'sort_order' => 2],
    ]);

    return $session;
}

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-WSH-MOB',
        'name' => 'SPPG Pencucian Mobile',
        'slug' => 'sppg-pencucian-mobile',
        'is_active' => true,
    ]);
    $this->washingActor = mobileWashingActor();
    Sanctum::actingAs($this->washingActor, ['mobile']);
});

it('locks automatic session data and exposes only the action for the current washing stage', function (): void {
    $definition = app(\App\Support\Mobile\MobileWorkspaceRegistry::class)->definitions()['pencucian'];
    expect($definition['allow_update'])->toBeFalse();
    foreach ($definition['fields'] as $field) {
        expect($field['editable'])->toBeFalse();
    }

    $session = mobileWashingSession($this->unit);
    $planned = $this->getJson("/api/mobile/operational-modules/pencucian/records/{$session->id}")
        ->assertOk();
    expect(collect($planned->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('receive')
        ->not->toContain('start', 'complete');

    $this->postJson("/api/mobile/operational-modules/pencucian/records/{$session->id}/actions/receive", [
        'fields' => ['received_containers' => 30, 'damaged_containers' => 0],
    ])->assertOk();

    $received = $this->getJson("/api/mobile/operational-modules/pencucian/records/{$session->id}")
        ->assertOk();
    expect(collect($received->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('waste_none', 'waste_present')
        ->not->toContain('start');
});

it('finishes a no-waste washing session through checklist and result photo', function (): void {
    $session = mobileWashingSession($this->unit);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $this->postJson("{$baseUrl}/actions/receive", [
        'fields' => ['received_containers' => 30, 'damaged_containers' => 0],
    ])->assertOk();
    $this->postJson("{$baseUrl}/actions/waste_none")->assertOk();
    $this->postJson("{$baseUrl}/actions/start")->assertOk();

    foreach ($session->checklistItems()->get() as $checklist) {
        $this->postJson("{$baseUrl}/relations/checklistItems/{$checklist->id}/actions/check")
            ->assertOk();
    }

    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));
    $this->postJson("{$baseUrl}/relations/documentations", [
        'fields' => ['caption' => 'Hasil pencucian bersih'],
        'files' => ['photo_path' => $photo],
    ])->assertCreated();

    $this->postJson("{$baseUrl}/actions/complete", [
        'fields' => ['clean_containers' => 30, 'damaged_containers' => 0],
    ])->assertOk();

    $session->refresh();
    expect($session->state->value)->toBe('ready')
        ->and($session->ready_at)->not->toBeNull()
        ->and($session->clean_containers)->toBe(30)
        ->and($session->documentations()->value('phase'))->toBe('after');
});

it('requires all washing evidence before completion', function (): void {
    $session = mobileWashingSession($this->unit);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $this->postJson("{$baseUrl}/actions/receive", [
        'fields' => ['received_containers' => 30, 'damaged_containers' => 0],
    ])->assertOk();
    $this->postJson("{$baseUrl}/actions/waste_none")->assertOk();
    $this->postJson("{$baseUrl}/actions/start")->assertOk();

    $this->postJson("{$baseUrl}/actions/complete", [
        'fields' => ['clean_containers' => 30, 'damaged_containers' => 0],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('checklist');

    expect($session->refresh()->state->value)->toBe('washing');
});
