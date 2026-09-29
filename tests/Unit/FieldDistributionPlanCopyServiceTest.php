<?php

use App\Models\FieldDistributionPlan;
use App\Models\User;
use App\Services\FieldDistributionPlanCopyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['database.connections.field_plan_copy_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('field_plan_copy_test');
    DB::setDefaultConnection('field_plan_copy_test');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    Carbon::setTestNow('2026-09-29 10:00:00');

    Schema::create('field_distribution_plans', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('sppg_unit_id');
        $table->date('distribution_date');
        $table->string('plan_number');
        $table->string('status');
        $table->text('general_notes')->nullable();
        $table->unsignedInteger('planned_beneficiaries')->default(0);
        $table->unsignedInteger('confirmed_beneficiaries')->default(0);
        $table->unsignedInteger('planned_small_portions')->default(0);
        $table->unsignedInteger('planned_large_portions')->default(0);
        $table->unsignedInteger('planned_total_portions')->default(0);
        $table->unsignedInteger('destination_count')->default(0);
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('field_distribution_plan_destinations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('field_distribution_plan_id');
        $table->string('destination_type');
        $table->unsignedBigInteger('destination_id');
        $table->string('destination_code_snapshot')->nullable();
        $table->string('route_name')->nullable();
        $table->unsignedInteger('sequence_order')->default(1);
        $table->time('planned_departure_time')->nullable();
        $table->time('planned_arrival_time')->nullable();
        $table->dateTime('planned_departure_at')->nullable();
        $table->dateTime('planned_arrival_at')->nullable();
        $table->text('special_notes')->nullable();
        $table->unsignedInteger('registered_beneficiaries')->default(0);
        $table->unsignedInteger('confirmed_beneficiaries')->default(0);
        $table->unsignedInteger('small_portions')->default(0);
        $table->unsignedInteger('large_portions')->default(0);
        $table->unsignedInteger('total_portions')->default(0);
        $table->string('confirmation_status')->nullable();
        $table->dateTime('confirmed_at')->nullable();
        $table->string('confirmed_by_name')->nullable();
        $table->text('change_reason')->nullable();
        $table->timestamps();
    });
    Schema::create('field_distribution_plan_recipient_groups', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('field_distribution_plan_destination_id');
        $table->unsignedBigInteger('beneficiary_category_id');
        $table->string('beneficiary_category_code_snapshot');
        $table->string('menu_audience');
        $table->string('portion_size');
        $table->unsignedInteger('registered_beneficiaries');
        $table->unsignedInteger('confirmed_beneficiaries');
        $table->unsignedInteger('small_portions')->default(0);
        $table->unsignedInteger('large_portions')->default(0);
        $table->unsignedInteger('total_portions')->default(0);
        $table->text('notes')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function planCopyFixture(string $date, string $number, int $registered, int $confirmed, string $route): FieldDistributionPlan
{
    $planId = DB::table('field_distribution_plans')->insertGetId([
        'sppg_unit_id' => 1, 'distribution_date' => $date, 'plan_number' => $number,
        'status' => 'draft', 'general_notes' => 'Catatan '.$number,
    ]);
    $destinationId = DB::table('field_distribution_plan_destinations')->insertGetId([
        'field_distribution_plan_id' => $planId, 'destination_type' => 'school',
        'destination_id' => 10, 'destination_code_snapshot' => 'SCH-10',
        'route_name' => $route, 'sequence_order' => 2,
        'registered_beneficiaries' => $registered, 'confirmed_beneficiaries' => $confirmed,
        'small_portions' => $confirmed, 'total_portions' => $confirmed,
        'confirmation_status' => 'confirmed', 'special_notes' => 'Catatan tujuan',
    ]);
    DB::table('field_distribution_plan_recipient_groups')->insert([
        'field_distribution_plan_destination_id' => $destinationId,
        'beneficiary_category_id' => 5, 'beneficiary_category_code_snapshot' => 'SD',
        'menu_audience' => 'student', 'portion_size' => 'small',
        'registered_beneficiaries' => $registered, 'confirmed_beneficiaries' => $confirmed,
        'small_portions' => $confirmed, 'total_portions' => $confirmed,
    ]);

    return FieldDistributionPlan::query()->findOrFail($planId);
}

it('copies matching route and actual portions without replacing the target beneficiary master', function (): void {
    $source = planCopyFixture('2026-09-28', 'RDL-OLD', 100, 80, 'Rute 2');
    DB::table('field_distribution_plans')->where('id', $source->id)->update(['status' => 'activated']);
    $target = planCopyFixture('2026-09-30', 'RDL-NEW', 90, 90, 'Rute Utama');
    $actor = new User(['name' => 'Asisten Lapangan']);

    $result = app(FieldDistributionPlanCopyService::class)->copyToPlan($target, $actor, 'yesterday');
    $destination = $target->refresh()->destinations()->firstOrFail();

    expect($result)->toBe(['copied_destinations' => 1, 'unmatched_destinations' => 0])
        ->and($destination->route_name)->toBe('Rute 2')
        ->and($destination->registered_beneficiaries)->toBe(90)
        ->and($destination->confirmed_beneficiaries)->toBe(80)
        ->and($destination->confirmation_status)->toBe('changed')
        ->and($destination->change_reason)->not->toBeNull()
        ->and($target->status->value)->toBe('draft')
        ->and($target->general_notes)->toBe('Catatan RDL-NEW')
        ->and($source->fresh()->destinations()->firstOrFail()->confirmed_beneficiaries)->toBe(80);
});

it('does not allow copying a plan onto the same distribution date', function (): void {
    $target = planCopyFixture('2026-09-29', 'RDL-TODAY', 90, 90, 'Rute 1');

    expect(fn () => app(FieldDistributionPlanCopyService::class)
        ->copyToPlan($target, new User(['name' => 'Asisten Lapangan']), 'today'))
        ->toThrow(ValidationException::class);
});

it('rejects a source with no matching beneficiary destination', function (): void {
    planCopyFixture('2026-09-28', 'RDL-OLD', 100, 100, 'Rute 1');
    $target = planCopyFixture('2026-09-30', 'RDL-NEW', 90, 90, 'Rute Utama');
    DB::table('field_distribution_plan_destinations')
        ->where('field_distribution_plan_id', $target->id)
        ->update(['destination_id' => 20]);

    expect(fn () => app(FieldDistributionPlanCopyService::class)
        ->copyToPlan($target, new User(['name' => 'Asisten Lapangan']), 'yesterday'))
        ->toThrow(ValidationException::class);
});
