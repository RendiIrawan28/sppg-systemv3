<?php

namespace Tests\Feature;

use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\FieldDistributionPlanStatus;
use App\Enums\OperationalReportStatus;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingSession;
use App\Services\DistributionWorkflow;
use App\Services\FieldOperationalPlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DistributionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_claim_complete_and_return_from_a_route(): void
    {
        [$run, $driver] = $this->runAndDriver();
        $stop = $run->stops()->create([
            'route_name' => 'Rute Timur',
            'destination_name' => 'SD Negeri Uji',
            'destination_type' => 'school',
            'sequence_order' => 1,
            'small_portions' => 40,
            'large_portions' => 60,
            'containers_sent' => 100,
        ]);
        $workflow = app(DistributionWorkflow::class);

        $workflow->claimRoute($run, $driver, [
            'vehicle_name' => 'Mobil Box',
            'vehicle_plate' => 'AB 1234 MBG',
            'kernet_name' => 'Kernet Uji',
        ]);

        $run->refresh();
        $this->assertSame(DistributionRunState::Assigned, $run->state);
        $this->assertSame($driver->name, $run->driver_name);
        $this->assertSame($driver->id, $run->petugas_id);

        $workflow->startLoading($run, $driver);
        $this->assertSame(DistributionRunState::Loaded, $run->fresh()->state);
        $this->assertSame(100, $run->fresh()->loaded_total);

        $workflow->depart($run->fresh(), $driver, []);
        $this->assertSame(DistributionRunState::Departed, $run->fresh()->state);
        $this->assertSame(DistributionStopStatus::InTransit, $stop->fresh()->status);

        $stop->update([
            'delivered_small_portions' => 40,
            'delivered_large_portions' => 58,
            'recipient_name' => 'Ibu Penerima',
            'recipient_position' => 'Guru',
            'handover_photo_path' => 'test/distribution-handover.jpg',
            'failure_reason' => 'Dua penerima tidak hadir.',
        ]);
        $workflow->completeStop($run->fresh(), $stop->fresh(), $driver);

        $stop->refresh();
        $run->refresh();
        $this->assertSame(DistributionStopStatus::Partial, $stop->status);
        $this->assertSame(2, $stop->returned_large_portions);
        $this->assertSame(DistributionRunState::DestinationsCompleted, $run->state);

        $workflow->finish($run, $driver, [
            'containers_returned' => 98,
            'containers_damaged' => 1,
            'containers_lost' => 1,
        ]);

        $run->refresh();
        $this->assertSame(DistributionRunState::Returned, $run->state);
        $this->assertSame(OperationalReportStatus::Draft, $run->status);
        $this->assertSame(0, $run->unaccounted_total);

        $washing = WashingSession::query()
            ->where('distribution_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(100, $washing->expected_containers);
        $this->assertSame(99, $washing->received_containers);
        $this->assertSame(1, $washing->missing_containers);
    }

    public function test_driver_cannot_claim_second_route_before_returning(): void
    {
        [$firstRun, $driver, $unit] = $this->runAndDriver(includeUnit: true);
        $secondRun = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id,
            'route_name' => 'Rute Kedua',
            'distribution_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $driver->id,
        ]);

        $workflow = app(DistributionWorkflow::class);
        $workflow->claimRoute($firstRun, $driver, [
            'vehicle_name' => 'Mobil Box',
            'vehicle_plate' => 'AB 1 MBG',
            'kernet_name' => 'Kernet Uji',
        ]);

        try {
            $workflow->claimRoute($secondRun, $driver, [
                'vehicle_name' => 'Mobil Box',
                'vehicle_plate' => 'AB 2 MBG',
                'kernet_name' => 'Kernet Uji',
            ]);
            $this->fail('Driver seharusnya tidak dapat memilih rute kedua.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('masih memiliki rute aktif', $message);
        }
    }

    public function test_partial_delivery_requires_reason(): void
    {
        [$run, $driver] = $this->runAndDriver();
        $stop = $run->stops()->create([
            'route_name' => 'Rute 1',
            'destination_name' => 'Sekolah 1',
            'destination_type' => 'school',
            'sequence_order' => 1,
            'small_portions' => 10,
            'large_portions' => 10,
            'containers_sent' => 20,
        ]);

        $workflow = app(DistributionWorkflow::class);
        $workflow->claimRoute($run, $driver, [
            'vehicle_name' => 'Mobil Box',
            'vehicle_plate' => 'AB 2 MBG',
            'kernet_name' => 'Kernet Uji',
        ]);
        $workflow->startLoading($run->fresh(), $driver);
        $workflow->depart($run->fresh(), $driver, []);

        $stop->update([
            'delivered_small_portions' => 9,
            'delivered_large_portions' => 10,
            'recipient_name' => 'Penerima',
            'handover_photo_path' => 'test/photo.jpg',
        ]);

        try {
            $workflow->completeStop($run->fresh(), $stop->fresh(), $driver);
            $this->fail('Pengiriman sebagian tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('Alasan pengiriman sebagian wajib diisi', $message);
        }
    }

    public function test_daily_report_can_only_be_submitted_after_all_routes_return(): void
    {
        [$firstRun, $driver, $unit] = $this->runAndDriver(includeUnit: true);
        $plan = FieldDistributionPlan::query()->create([
            'sppg_unit_id' => $unit->id,
            'distribution_date' => today(),
            'service_date' => today(),
            'production_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'status' => FieldDistributionPlanStatus::Completed,
            'created_by' => $driver->id,
        ]);
        $firstRun->update([
            'field_distribution_plan_id' => $plan->id,
            'state' => DistributionRunState::Returned,
            'loaded_small_portions' => 0,
            'loaded_large_portions' => 0,
        ]);
        $secondRun = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id,
            'field_distribution_plan_id' => $plan->id,
            'route_name' => 'Rute Kedua',
            'distribution_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $driver->id,
        ]);
        $firstRun->stops()->create([
            'route_name' => 'Rute Pertama',
            'destination_name' => 'Tujuan Pertama',
            'destination_type' => 'school',
            'sequence_order' => 1,
            'status' => DistributionStopStatus::Failed,
            'failure_reason' => 'Tidak ada porsi pada pengujian.',
        ]);
        $secondRun->stops()->create([
            'route_name' => 'Rute Kedua',
            'destination_name' => 'Tujuan Kedua',
            'destination_type' => 'school',
            'sequence_order' => 1,
            'status' => DistributionStopStatus::Failed,
            'failure_reason' => 'Tidak ada porsi pada pengujian.',
        ]);

        $workflow = app(DistributionWorkflow::class);

        try {
            $workflow->submit($firstRun->fresh(), $driver);
            $this->fail('Laporan seharusnya belum dapat diajukan.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('belum kembali ke SPPG', $message);
        }

        $secondRun->update(['state' => DistributionRunState::Returned]);
        $workflow->submit($firstRun->fresh(), $driver);

        $this->assertSame(OperationalReportStatus::Submitted, $firstRun->fresh()->status);
        $this->assertSame(OperationalReportStatus::Submitted, $secondRun->fresh()->status);
    }

    public function test_generator_creates_one_distribution_run_per_route(): void
    {
        [$run, $actor, $unit] = $this->runAndDriver(includeUnit: true);
        $run->forceDelete();

        $plan = FieldDistributionPlan::query()->create([
            'sppg_unit_id' => $unit->id,
            'distribution_date' => today(),
            'service_date' => today(),
            'production_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'status' => FieldDistributionPlanStatus::Activated,
            'created_by' => $actor->id,
        ]);

        $plan->destinations()->createMany([
            [
                'destination_type' => 'school',
                'destination_name_snapshot' => 'SD A',
                'route_name' => 'Rute Utara',
                'sequence_order' => 1,
                'small_portions' => 10,
                'large_portions' => 20,
                'planned_arrival_time' => '08:00:00',
            ],
            [
                'destination_type' => 'school',
                'destination_name_snapshot' => 'SD B',
                'route_name' => 'Rute Utara',
                'sequence_order' => 2,
                'small_portions' => 5,
                'large_portions' => 15,
                'planned_arrival_time' => '08:30:00',
            ],
            [
                'destination_type' => 'posyandu',
                'destination_name_snapshot' => 'Posyandu A',
                'route_name' => 'Rute Selatan',
                'sequence_order' => 1,
                'small_portions' => 25,
                'large_portions' => 0,
                'planned_arrival_time' => '08:15:00',
            ],
        ]);

        $runs = app(FieldOperationalPlanGenerator::class)
            ->generateDistributionRuns($plan->refresh(), $actor);

        $this->assertCount(2, $runs);
        $this->assertSame(['Rute Selatan', 'Rute Utara'], $runs->pluck('route_name')->sort()->values()->all());
        $this->assertSame(2, $runs->firstWhere('route_name', 'Rute Utara')->stops()->count());
        $this->assertSame(1, $runs->firstWhere('route_name', 'Rute Selatan')->stops()->count());
    }

    /** @return array{0: DistributionRun, 1: User, 2?: SppgUnit} */
    private function runAndDriver(bool $includeUnit = false): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-DIST',
            'name' => 'SPPG Distribusi',
            'slug' => 'sppg-distribusi',
            'is_active' => true,
        ]);
        $driver = User::factory()->create();
        $driver->givePermissionTo([
            Permission::findOrCreate('distribution.update'),
            Permission::findOrCreate('distribution.submit'),
        ]);
        $run = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id,
            'route_name' => 'Rute Pertama',
            'distribution_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $driver->id,
        ]);

        return $includeUnit ? [$run, $driver, $unit] : [$run, $driver];
    }
}
