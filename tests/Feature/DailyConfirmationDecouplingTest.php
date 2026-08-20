<?php

use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodCategoryTotal;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\FieldDistributionPlan;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\MobileDailyBeneficiaryConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('keeps daily confirmation independent from manual distribution plans', function (): void {
    $serviceDate = today()->addDays(3);
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-CONF', 'name' => 'SPPG Konfirmasi', 'slug' => 'sppg-konfirmasi', 'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Asisten Lapangan', 'email' => 'aslap-konfirmasi@example.test', 'password' => 'password',
        'is_active' => true,
    ]);
    $actor->givePermissionTo([
        Permission::findOrCreate('daily_beneficiary_confirmations.create', 'web'),
        Permission::findOrCreate('daily_beneficiary_confirmations.submit', 'web'),
    ]);
    $period = BeneficiaryPeriod::query()->create([
        'sppg_unit_id' => $unit->id, 'code' => 'PER-01', 'name' => 'Periode Aktif',
        'start_date' => today()->subDay(), 'end_date' => today()->addWeek(), 'status' => 'active',
        'active_members' => 3200, 'total_members' => 3200, 'destination_count' => 1,
    ]);
    $destination = BeneficiaryPeriodDestination::query()->create([
        'beneficiary_period_id' => $period->id, 'destination_key' => 'school:1',
        'destination_type' => 'school', 'destination_id' => 1,
        'destination_code_snapshot' => 'SD-01', 'destination_name_snapshot' => 'SD Uji',
        'sort_order' => 1, 'is_active' => true,
    ]);
    BeneficiaryPeriodCategoryTotal::query()->create([
        'beneficiary_period_id' => $period->id,
        'beneficiary_period_destination_id' => $destination->id,
        'beneficiary_category_code_snapshot' => 'SD-KECIL',
        'beneficiary_category_name_snapshot' => 'SD Kelas 1-3',
        'portion_category' => 'small', 'menu_audience' => 'student',
        'total_beneficiaries' => 3200,
    ]);
    $period->forceFill(['active_members' => 3200, 'total_members' => 3200, 'destination_count' => 1])->save();
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id, 'distribution_date' => $serviceDate, 'service_date' => $serviceDate,
        'planned_beneficiaries' => 3050, 'confirmed_beneficiaries' => 3050,
        'planned_small_portions' => 3050, 'planned_large_portions' => 0,
        'planned_total_portions' => 3050, 'destination_count' => 0,
        'status' => 'draft', 'created_by' => $actor->id,
    ]);
    BeneficiaryPeriod::query()->whereKey($period->id)->update([
        'active_members' => 3200, 'total_members' => 3200, 'destination_count' => 1,
    ]);

    $service = app(MobileDailyBeneficiaryConfirmationService::class);
    $confirmation = $service->generateForDate($unit->id, $serviceDate->toDateString(), $actor)->firstOrFail();
    $service->confirm($confirmation, $actor);

    expect(FieldDistributionPlan::query()->count())->toBe(1)
        ->and($plan->refresh()->confirmed_beneficiaries)->toBe(3050)
        ->and($plan->actual_data_synced_at)->toBeNull()
        ->and($confirmation->refresh()->items->sum('actual_count'))->toBe(3200);
});
