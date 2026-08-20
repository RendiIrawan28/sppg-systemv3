<?php

use App\Enums\UserRole;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mobileDistributionActor(string $name): User
{
    $role = Role::findOrCreate(UserRole::PetugasDistribusi->value, 'web');
    foreach (['distribution.view', 'distribution.update', 'distribution.submit', 'distribution.export'] as $permission) {
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

function mobileDistributionRun(SppgUnit $unit, array $values = []): DistributionRun
{
    return DistributionRun::query()->create([
        'sppg_unit_id' => $unit->id,
        'distribution_date' => today(),
        'route_name' => $values['route_name'] ?? 'Rute Uji',
        'menu_name_snapshot' => 'Menu Uji',
        'state' => $values['state'] ?? 'planned',
        'status' => $values['status'] ?? 'draft',
        'petugas_id' => $values['petugas_id'] ?? null,
        'driver_name' => $values['driver_name'] ?? null,
        'petugas_name_snapshot' => $values['driver_name'] ?? null,
        'field_distribution_plan_id' => $values['field_distribution_plan_id'] ?? null,
    ]);
}

beforeEach(function (): void {
    $this->unit = SppgUnit::query()->create([
        'code' => 'SPPG-DST-MOB',
        'name' => 'SPPG Distribusi Mobile',
        'slug' => 'sppg-distribusi-mobile',
        'is_active' => true,
    ]);
    $this->driver = mobileDistributionActor('Driver Utama');
    $this->otherDriver = mobileDistributionActor('Driver Lain');
});

it('only exposes available routes and routes belonging to the signed in driver', function (): void {
    $available = mobileDistributionRun($this->unit, ['route_name' => 'Rute Tersedia']);
    $mine = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Saya',
        'state' => 'assigned',
        'petugas_id' => $this->driver->id,
        'driver_name' => $this->driver->name,
    ]);
    $other = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Driver Lain',
        'state' => 'assigned',
        'petugas_id' => $this->otherDriver->id,
        'driver_name' => $this->otherDriver->name,
    ]);

    Sanctum::actingAs($this->driver, ['mobile']);

    $response = $this->getJson('/api/mobile/operational-modules/distribusi/records')
        ->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($available->id, $mine->id)
        ->not->toContain($other->id);

    $this->getJson("/api/mobile/operational-modules/distribusi/records/{$other->id}")
        ->assertNotFound();
});

it('completes a destination with one mobile serah terima form', function (): void {
    $run = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Serah Terima',
        'state' => 'departed',
        'petugas_id' => $this->driver->id,
        'driver_name' => $this->driver->name,
    ]);
    $stop = $run->stops()->create([
        'route_name' => $run->route_name,
        'destination_name' => 'Sekolah Tujuan',
        'sequence_order' => 1,
        'small_portions' => 10,
        'large_portions' => 20,
        'status' => 'in_transit',
    ]);
    Sanctum::actingAs($this->driver, ['mobile']);

    $this->postJson("/api/mobile/operational-modules/distribusi/records/{$run->id}/relations/stops/{$stop->id}/actions/arrive")
        ->assertOk();

    $detail = $this->getJson("/api/mobile/operational-modules/distribusi/records/{$run->id}")
        ->assertOk();
    $stopSection = collect($detail->json('data.sections'))->firstWhere('key', 'stops');
    $deliver = collect($stopSection['items'][0]['actions'])->firstWhere('key', 'deliver');
    expect(collect($deliver['fields'])->pluck('key')->all())
        ->toContain('recipient_name', 'handover_photo_path', 'delivered_small_portions', 'containers_sent');

    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));
    $this->postJson("/api/mobile/operational-modules/distribusi/records/{$run->id}/relations/stops/{$stop->id}/actions/deliver", [
        'fields' => [
            'delivered_small_portions' => '10',
            'delivered_large_portions' => '20',
            'containers_sent' => '30',
            'recipient_name' => 'Penerima Uji',
            'recipient_position' => 'Guru',
        ],
        'files' => ['handover_photo_path' => $photo],
    ])->assertOk();

    expect($stop->refresh()->status->value)->toBe('delivered')
        ->and($stop->recipient_name)->toBe('Penerima Uji')
        ->and($stop->handover_photo_path)->not->toBeNull()
        ->and($run->refresh()->state->value)->toBe('destinations_completed');
});

it('locks generated route identity and exposes explicit reorder controls', function (): void {
    $definition = app(\App\Support\Mobile\MobileWorkspaceRegistry::class)->definitions()['distribusi'];
    expect($definition['allow_update'])->toBeFalse();
    foreach ($definition['fields'] as $field) {
        expect($field['editable'])->toBeFalse();
    }

    $run = mobileDistributionRun($this->unit, [
        'state' => 'assigned',
        'petugas_id' => $this->driver->id,
        'driver_name' => $this->driver->name,
    ]);
    $first = $run->stops()->create([
        'route_name' => $run->route_name, 'destination_name' => 'Tujuan A',
        'sequence_order' => 1, 'small_portions' => 5, 'large_portions' => 5,
    ]);
    $second = $run->stops()->create([
        'route_name' => $run->route_name, 'destination_name' => 'Tujuan B',
        'sequence_order' => 2, 'small_portions' => 5, 'large_portions' => 5,
    ]);
    Sanctum::actingAs($this->driver, ['mobile']);

    $detail = $this->getJson("/api/mobile/operational-modules/distribusi/records/{$run->id}")
        ->assertOk();
    $items = collect(collect($detail->json('data.sections'))->firstWhere('key', 'stops')['items']);
    expect(collect($items->firstWhere('id', $first->id)['actions'])->pluck('key')->all())->toContain('move_down')
        ->and(collect($items->firstWhere('id', $second->id)['actions'])->pluck('key')->all())->toContain('move_up');

    $this->postJson("/api/mobile/operational-modules/distribusi/records/{$run->id}/relations/stops/{$first->id}/actions/move_down")
        ->assertOk();
    expect($first->refresh()->sequence_order)->toBe(2)
        ->and($second->refresh()->sequence_order)->toBe(1);
});

it('only offers report submission after every route in the plan has returned', function (): void {
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'plan_number' => 'RDL/DST/MOBILE',
        'plan_year' => 2026,
        'sequence_number' => 1,
        'distribution_date' => today(),
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Uji',
        'planned_small_portions' => 10,
        'planned_large_portions' => 10,
        'planned_total_portions' => 20,
        'status' => 'activated',
        'created_by' => $this->driver->id,
    ]);
    $returned = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Selesai',
        'state' => 'returned',
        'petugas_id' => $this->driver->id,
        'driver_name' => $this->driver->name,
        'field_distribution_plan_id' => $plan->id,
    ]);
    $pending = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Belum Dipilih',
        'field_distribution_plan_id' => $plan->id,
    ]);
    Sanctum::actingAs($this->driver, ['mobile']);

    $before = $this->getJson("/api/mobile/operational-modules/distribusi/records/{$returned->id}")
        ->assertOk();
    expect(collect($before->json('data.capabilities.actions'))->pluck('key')->all())
        ->not->toContain('submit');

    $pending->update([
        'state' => 'returned',
        'petugas_id' => $this->otherDriver->id,
        'driver_name' => $this->otherDriver->name,
    ]);
    $after = $this->getJson("/api/mobile/operational-modules/distribusi/records/{$returned->id}")
        ->assertOk();
    expect(collect($after->json('data.capabilities.actions'))->pluck('key')->all())
        ->toContain('submit');
});

it('returns collected containers to SPPG and generates the textual daily summary', function (): void {
    $distributionRun = mobileDistributionRun($this->unit, [
        'route_name' => 'Rute Pengambilan',
        'state' => 'returned',
        'petugas_id' => $this->driver->id,
        'driver_name' => $this->driver->name,
    ]);
    $stop = $distributionRun->stops()->create([
        'route_name' => $distributionRun->route_name,
        'destination_name' => 'Sekolah Uji',
        'sequence_order' => 1,
        'small_portions' => 10,
        'large_portions' => 10,
        'containers_sent' => 20,
        'status' => 'delivered',
    ]);
    $run = \App\Models\ContainerCollectionRun::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'collection_date' => today(),
        'state' => \App\Models\ContainerCollectionRun::ACTIVE,
        'driver_id' => $this->driver->id,
        'driver_name_snapshot' => $this->driver->name,
    ]);
    $task = \App\Models\ContainerCollectionTask::query()->create([
        'sppg_unit_id' => $this->unit->id,
        'distribution_run_id' => $distributionRun->id,
        'distribution_stop_id' => $stop->id,
        'delivery_date' => today(),
        'destination_name' => 'Sekolah Uji',
        'target_containers' => 20,
        'collected_containers' => 20,
        'remaining_containers' => 0,
        'status' => \App\Models\ContainerCollectionTask::COLLECTED,
        'available_at' => now(),
        'completed_at' => now(),
    ]);
    $run->items()->create([
        'container_collection_task_id' => $task->id,
        'collected_quantity' => 20,
        'status' => \App\Models\ContainerCollectionTask::COLLECTED,
        'collected_by' => $this->driver->id,
        'collected_at' => now(),
    ]);
    Sanctum::actingAs($this->driver, ['mobile']);

    $this->postJson("/api/mobile/operational-modules/pengambilan-ompreng/records/{$run->id}/actions/return")
        ->assertOk();

    expect($run->refresh()->state)->toBe(\App\Models\ContainerCollectionRun::RETURNED)
        ->and($run->washingSession()->exists())->toBeTrue();
    $report = \App\Models\FieldDailyReport::query()->whereDate('report_date', today())->firstOrFail();
    expect($report->operational_summary)
        ->toBe('Rekap otomatis dibentuk setelah seluruh pengantaran dan pengambilan ompreng selesai.');
});
