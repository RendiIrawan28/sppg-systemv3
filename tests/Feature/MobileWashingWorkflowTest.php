<?php

use App\Enums\UserRole;
use App\Livewire\V3\Operations\Form as WashingForm;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingSession;
use App\Support\Mobile\MobileWorkspaceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-08-24 09:00:00')));

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
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['pencucian'];
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

    $this->postJson("/api/mobile/operational-modules/pencucian/records/{$session->id}/actions/receive")
        ->assertOk();
    expect($session->refresh()->received_containers)->toBe(0);

    $received = $this->getJson("/api/mobile/operational-modules/pencucian/records/{$session->id}")
        ->assertOk();
    expect(collect($received->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('waste_none', 'waste_present')
        ->not->toContain('start');
});

it('finishes a no-waste washing session through checklist and result photo', function (): void {
    $session = mobileWashingSession($this->unit);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $this->postJson("{$baseUrl}/actions/receive")->assertOk();
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
        ->and($session->received_containers)->toBe(30)
        ->and($session->clean_containers)->toBe(30)
        ->and($session->documentations()->value('phase'))->toBe('after');
});

it('uses the washing result as final count and requires an explanation for a difference', function (): void {
    $session = mobileWashingSession($this->unit);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $this->postJson("{$baseUrl}/actions/receive")->assertOk();
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
        'fields' => ['caption' => 'Hasil hitung akhir'],
        'files' => ['photo_path' => $photo],
    ])->assertCreated();

    $this->postJson("{$baseUrl}/actions/complete", [
        'fields' => ['clean_containers' => 28, 'damaged_containers' => 1],
    ])->assertUnprocessable()->assertJsonValidationErrors('reconciliation_notes');

    $this->postJson("{$baseUrl}/actions/complete", [
        'fields' => [
            'clean_containers' => 28,
            'damaged_containers' => 1,
            'notes' => 'Perkiraan driver lebih satu; hitungan akhir setelah pencucian 29.',
        ],
    ])->assertOk();

    $session->refresh();
    expect($session->state->value)->toBe('ready')
        ->and($session->received_containers)->toBe(29)
        ->and($session->clean_containers)->toBe(28)
        ->and($session->damaged_containers)->toBe(1)
        ->and($session->receiving_difference)->toBe(-1)
        ->and($session->missing_containers)->toBe(0)
        ->and($session->notes)->toContain('Rekonsiliasi:');
});

it('requires all washing evidence before completion', function (): void {
    $session = mobileWashingSession($this->unit);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $this->postJson("{$baseUrl}/actions/receive")->assertOk();
    $this->postJson("{$baseUrl}/actions/waste_none")->assertOk();
    $this->postJson("{$baseUrl}/actions/start")->assertOk();

    $this->postJson("{$baseUrl}/actions/complete", [
        'fields' => ['clean_containers' => 30, 'damaged_containers' => 0],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('checklist');

    expect($session->refresh()->state->value)->toBe('washing');
});

it('lets staff correct a returned washing report without reopening operational stages', function (): void {
    $session = mobileWashingSession($this->unit, [
        'state' => 'ready',
        'status' => 'revision_required',
    ]);
    $session->update([
        'clean_containers' => 30,
        'damaged_containers' => 0,
        'received_containers' => 30,
        'washed_containers' => 30,
        'has_food_waste' => false,
        'no_waste_confirmed' => true,
        'waste_recorded_at' => now(),
        'review_notes' => 'Perbaiki jumlah ompreng dan dokumentasi.',
    ]);
    $session->checklistItems()->update(['is_passed' => true]);
    $session->documentations()->create([
        'phase' => 'after',
        'photo_path' => 'washing-test/old-result.png',
        'caption' => 'Foto awal',
    ]);
    $baseUrl = "/api/mobile/operational-modules/pencucian/records/{$session->id}";

    $detail = $this->getJson($baseUrl)->assertOk();
    expect(collect($detail->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('revise_result', 'submit')
        ->not->toContain('receive', 'start', 'complete');
    expect(collect($detail->json('data.fields'))->firstWhere('key', 'review_notes')['value'])
        ->toBe('Perbaiki jumlah ompreng dan dokumentasi.');

    $this->postJson("{$baseUrl}/actions/revise_result", [
        'fields' => ['clean_containers' => 28, 'damaged_containers' => 1],
    ])->assertUnprocessable()->assertJsonValidationErrors('notes');

    $this->postJson("{$baseUrl}/actions/revise_result", [
        'fields' => [
            'clean_containers' => 28,
            'damaged_containers' => 1,
            'notes' => 'Satu ompreng belum kembali dari perkiraan pengambilan.',
        ],
    ])->assertOk();

    $checklist = $session->checklistItems()->firstOrFail();
    $this->postJson("{$baseUrl}/relations/checklistItems/{$checklist->id}/actions/uncheck")
        ->assertOk();
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));
    $this->postJson("{$baseUrl}/relations/documentations", [
        'fields' => ['caption' => 'Foto koreksi pencucian'],
        'files' => ['photo_path' => $photo],
    ])->assertCreated();
    $this->postJson("{$baseUrl}/actions/submit")
        ->assertUnprocessable();
    $this->postJson("{$baseUrl}/relations/checklistItems/{$checklist->id}/actions/check")
        ->assertOk();

    $session->refresh();
    expect($session->state->value)->toBe('ready')
        ->and($session->status->value)->toBe('revision_required')
        ->and($session->received_containers)->toBe(29)
        ->and($session->washed_containers)->toBe(29)
        ->and($session->receiving_difference)->toBe(-1);

    $this->postJson("{$baseUrl}/actions/submit")->assertOk();
    expect($session->refresh()->status->value)->toBe('submitted');
    $this->postJson("{$baseUrl}/actions/revise_result", [
        'fields' => ['clean_containers' => 30, 'damaged_containers' => 0],
    ])->assertUnprocessable();
});

it('lets staff save web washing corrections while the session stays ready', function (): void {
    $session = mobileWashingSession($this->unit, [
        'state' => 'ready',
        'status' => 'revision_required',
    ]);
    $session->update([
        'clean_containers' => 30,
        'damaged_containers' => 0,
        'received_containers' => 30,
        'washed_containers' => 30,
        'has_food_waste' => false,
        'no_waste_confirmed' => true,
        'waste_recorded_at' => now(),
        'review_notes' => 'Perbaiki jumlah ompreng.',
    ]);
    $session->checklistItems()->get()->each(fn ($item) => $item->update([
        'category' => 'Pencucian',
        'item_name' => $item->label ?: 'Pemeriksaan ompreng',
        'is_passed' => true,
    ]));
    $session->documentations()->create([
        'phase' => 'after',
        'photo_path' => 'washing-test/result.png',
        'caption' => 'Hasil pencucian',
    ]);

    Livewire::test(WashingForm::class, ['module' => 'pencucian', 'record' => $session->id])
        ->assertSee('Laporan perlu diperbaiki')
        ->assertSee('Perbaiki jumlah ompreng.')
        ->set('data.clean_containers', 29)
        ->set('data.notes', 'Satu ompreng rusak setelah pemeriksaan ulang.')
        ->set('relations.checklistItems.0.notes', 'Diperiksa ulang saat revisi.')
        ->call('save')
        ->assertHasNoErrors();

    expect($session->refresh()->state->value)->toBe('ready')
        ->and($session->status->value)->toBe('revision_required')
        ->and($session->received_containers)->toBe(29)
        ->and($session->receiving_difference)->toBe(-1);
    expect($session->checklistItems()->first()->notes)->toBe('Diperiksa ulang saat revisi.');
});
