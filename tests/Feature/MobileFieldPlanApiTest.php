<?php

use App\Enums\FieldDistributionPlanStatus;
use App\Enums\UserRole;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\FieldDistributionPlanRecipientGroup;
use App\Models\SppgUnit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('lists field plans for an authorized mobile user', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::AsistenLapangan);
    $plan = mobileFieldPlan();

    $this->withToken($token)
        ->getJson('/api/mobile/field-plans')
        ->assertOk()
        ->assertJsonPath('data.0.id', $plan->id)
        ->assertJsonPath('data.0.status', FieldDistributionPlanStatus::Activated->value)
        ->assertJsonPath('data.0.menu_name', 'Menu Sehat H-3')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('returns field plan detail with destinations and recipient groups', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::AsistenLapangan);
    $plan = mobileFieldPlan();
    $destination = FieldDistributionPlanDestination::query()->create([
        'field_distribution_plan_id' => $plan->id,
        'destination_type' => 'school',
        'destination_id' => 101,
        'destination_code_snapshot' => 'SCH-101',
        'destination_name_snapshot' => 'SD Negeri Harapan',
        'address_snapshot' => 'Jalan Pendidikan 1',
        'route_name' => 'Rute Utara',
        'sequence_order' => 1,
        'registered_beneficiaries' => 120,
        'confirmed_beneficiaries' => 118,
        'small_portions' => 40,
        'large_portions' => 78,
        'confirmation_status' => 'changed',
    ]);
    FieldDistributionPlanRecipientGroup::query()->create([
        'field_distribution_plan_destination_id' => $destination->id,
        'beneficiary_category_code_snapshot' => 'SD-BESAR',
        'beneficiary_category_name_snapshot' => 'Siswa Kelas Besar',
        'menu_audience' => 'school_large',
        'portion_size' => 'large',
        'registered_beneficiaries' => 80,
        'confirmed_beneficiaries' => 78,
    ]);

    $this->withToken($token)
        ->getJson("/api/mobile/field-plans/{$plan->id}")
        ->assertOk()
        ->assertJsonPath('data.destinations.0.name', 'SD Negeri Harapan')
        ->assertJsonPath('data.destinations.0.total_portions', 78)
        ->assertJsonPath('data.destinations.0.recipient_groups.0.category_name', 'Siswa Kelas Besar')
        ->assertJsonPath('data.destinations.0.recipient_groups.0.confirmed_beneficiaries', 78);
});

it('rejects field plan access when the role lacks permission', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::StafGudang);
    $plan = mobileFieldPlan();

    $this->withToken($token)
        ->getJson('/api/mobile/field-plans')
        ->assertForbidden();

    $this->withToken($token)
        ->getJson("/api/mobile/field-plans/{$plan->id}")
        ->assertForbidden();

    $this->withToken($token)
        ->putJson("/api/mobile/field-plans/{$plan->id}", ['destinations' => []])
        ->assertForbidden();

    $this->withToken($token)
        ->getJson("/api/mobile/field-plans/{$plan->id}/readiness")
        ->assertForbidden();

    $this->withToken($token)
        ->postJson("/api/mobile/field-plans/{$plan->id}/activate")
        ->assertForbidden();
});

it('updates actual recipients and destination schedule from mobile', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::AsistenLapangan);
    [$plan, $destination, $group] = editableMobileFieldPlan();

    $this->withToken($token)
        ->putJson("/api/mobile/field-plans/{$plan->id}", [
            'general_notes' => 'Konfirmasi dilakukan melalui aplikasi Android.',
            'destinations' => [[
                'id' => $destination->id,
                'route_name' => 'Rute Timur',
                'sequence_order' => 1,
                'planned_departure_time' => '07:15',
                'planned_arrival_time' => '08:00',
                'special_notes' => 'Hubungi PIC sebelum tiba.',
                'change_reason' => 'Dua siswa izin tidak masuk.',
                'recipient_groups' => [[
                    'id' => $group->id,
                    'confirmed_beneficiaries' => 78,
                    'notes' => 'Dua siswa izin.',
                ]],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmed_beneficiaries', 78)
        ->assertJsonPath('data.total_portions', 78)
        ->assertJsonPath('data.destinations.0.route_name', 'Rute Timur')
        ->assertJsonPath('data.destinations.0.confirmation_status', 'changed')
        ->assertJsonPath('data.destinations.0.change_reason', 'Dua siswa izin tidak masuk.');

    expect($plan->fresh()->general_notes)->toBe('Konfirmasi dilakukan melalui aplikasi Android.')
        ->and($destination->fresh()->planned_departure_time)->toBe('07:15')
        ->and($group->fresh()->confirmed_beneficiaries)->toBe(78);
});

it('requires a reason when the confirmed recipient count changes', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::AsistenLapangan);
    [$plan, $destination, $group] = editableMobileFieldPlan();

    $this->withToken($token)
        ->putJson("/api/mobile/field-plans/{$plan->id}", [
            'destinations' => [[
                'id' => $destination->id,
                'route_name' => 'Rute Timur',
                'sequence_order' => 1,
                'planned_departure_time' => '07:15',
                'planned_arrival_time' => '08:00',
                'change_reason' => '',
                'recipient_groups' => [[
                    'id' => $group->id,
                    'confirmed_beneficiaries' => 78,
                ]],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'SD Negeri Harapan: alasan perubahan jumlah penerima wajib diisi.']);

    expect($group->fresh()->confirmed_beneficiaries)->toBe(80);
});

it('checks readiness and activates a complete mobile field plan', function (): void {
    [$user, $token] = mobileFieldUser(UserRole::AsistenLapangan);
    [$plan] = editableMobileFieldPlan();

    $plan->destinations()->update([
        'planned_departure_time' => null,
        'planned_arrival_time' => null,
        'planned_departure_at' => null,
        'planned_arrival_at' => null,
    ]);

    $this->withToken($token)
        ->getJson("/api/mobile/field-plans/{$plan->id}/readiness")
        ->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('issues', []);

    $this->withToken($token)
        ->postJson("/api/mobile/field-plans/{$plan->id}/activate", [
            'notes' => 'Dikonfirmasi melalui aplikasi Android.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FieldDistributionPlanStatus::Activated->value);

    expect($plan->fresh()->status)->toBe(FieldDistributionPlanStatus::Activated)
        ->and($plan->fresh()->processing_batch_id)->toBeNull()
        ->and($plan->fresh()->portioning_session_id)->toBeNull()
        ->and($plan->fresh()->distribution_run_id)->toBeNull();
});

/** @return array{User, string} */
function mobileFieldUser(UserRole $role): array
{
    $user = User::factory()->create(['is_active' => true]);
    $user->syncRoles([$role->value]);

    return [$user, $user->createToken('Android Test', ['mobile'])->plainTextToken];
}

function mobileFieldPlan(): FieldDistributionPlan
{
    return FieldDistributionPlan::query()->create([
        'sppg_unit_id' => SppgUnit::query()->firstOrFail()->id,
        'distribution_date' => today()->addDays(3),
        'service_date' => today()->addDays(3),
        'production_date' => today()->addDays(2),
        'menu_name_snapshot' => 'Menu Sehat H-3',
        'shift' => 'morning',
        'planned_beneficiaries' => 120,
        'confirmed_beneficiaries' => 118,
        'planned_small_portions' => 40,
        'planned_large_portions' => 78,
        'planned_total_portions' => 118,
        'destination_count' => 1,
        'status' => FieldDistributionPlanStatus::Activated,
    ]);
}

/** @return array{FieldDistributionPlan, FieldDistributionPlanDestination, FieldDistributionPlanRecipientGroup} */
function editableMobileFieldPlan(): array
{
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => SppgUnit::query()->firstOrFail()->id,
        'menu_id' => 1,
        'menu_cycle_day_id' => 1,
        'distribution_date' => today()->addDays(3),
        'service_date' => today()->addDays(3),
        'production_date' => today()->addDays(2),
        'menu_name_snapshot' => 'Menu Sehat H-3',
        'shift' => 'morning',
        'status' => FieldDistributionPlanStatus::Draft,
    ]);
    $destination = FieldDistributionPlanDestination::query()->create([
        'field_distribution_plan_id' => $plan->id,
        'destination_type' => 'school',
        'destination_id' => 101,
        'destination_code_snapshot' => 'SCH-101',
        'destination_name_snapshot' => 'SD Negeri Harapan',
        'address_snapshot' => 'Jalan Pendidikan 1',
        'route_name' => 'Rute Utama',
        'sequence_order' => 1,
        'registered_beneficiaries' => 80,
        'confirmed_beneficiaries' => 80,
        'small_portions' => 0,
        'large_portions' => 80,
        'planned_departure_time' => '07:15',
        'planned_arrival_time' => '08:00',
        'confirmation_status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
    $group = FieldDistributionPlanRecipientGroup::query()->create([
        'field_distribution_plan_destination_id' => $destination->id,
        'beneficiary_category_code_snapshot' => 'SD-BESAR',
        'beneficiary_category_name_snapshot' => 'Siswa Kelas Besar',
        'menu_audience' => 'school_large',
        'portion_size' => 'large',
        'registered_beneficiaries' => 80,
        'confirmed_beneficiaries' => 80,
    ]);

    return [$plan->refresh(), $destination->refresh(), $group->refresh()];
}
