<?php

namespace Tests\Feature;

use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\OperationalReportStatus;
use App\Models\DistributionRun;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingSession;
use App\Services\DistributionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DistributionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_complete_simple_distribution_without_report_approval(): void
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

        $workflow->prepareLoad($run, $driver, [
            'vehicle_name' => 'Mobil Box',
            'vehicle_plate' => 'B 1234 MBG',
            'driver_name' => 'Driver Uji',
            'kernet_name' => 'Kernet Uji',
        ]);

        $run->refresh();
        $this->assertSame(DistributionRunState::Loaded, $run->state);
        $this->assertSame(100, $run->loaded_total);
        $this->assertSame($driver->id, $run->petugas_id);

        $workflow->depart($run, $driver, []);
        $this->assertSame(DistributionRunState::Departed, $run->fresh()->state);
        $this->assertSame(DistributionStopStatus::InTransit, $stop->fresh()->status);

        $workflow->arriveAtStop($run->fresh(), $stop->fresh(), $driver);
        $stop->refresh();
        $this->assertSame(DistributionStopStatus::Arrived, $stop->status);
        $this->assertNotNull($stop->arrived_at);
        $this->assertSame(40, $stop->delivered_small_portions);
        $this->assertSame(60, $stop->delivered_large_portions);

        $stop->update([
            'delivered_large_portions' => 58,
            'recipient_name' => 'Ibu Penerima',
            'handover_photo_path' => 'test/distribution-handover.jpg',
            'containers_returned' => 98,
            'containers_damaged' => 1,
            'containers_lost' => 1,
            'failure_reason' => 'Dua penerima tidak hadir.',
        ]);
        $workflow->completeStop($run->fresh(), $stop->fresh(), $driver);

        $stop->refresh();
        $this->assertSame(DistributionStopStatus::Partial, $stop->status);
        $this->assertSame(2, $stop->returned_large_portions);

        $workflow->finish($run->fresh(), $driver, []);
        $run->refresh();
        $this->assertSame(DistributionRunState::Returned, $run->state);
        $this->assertSame(OperationalReportStatus::Draft, $run->status);
        $this->assertSame(0, $run->unaccounted_total);
        $washing = WashingSession::query()->where('distribution_run_id', $run->id)->firstOrFail();
        $this->assertSame(100, $washing->expected_containers);
        $this->assertSame(99, $washing->received_containers);
        $this->assertSame(
            $washing->expected_containers,
            $washing->received_containers + $washing->missing_containers,
        );
        $this->assertGreaterThan(0, $washing->checklistItems()->count());
    }

    public function test_vehicle_driver_and_kernet_are_required_before_loading(): void
    {
        [$run, $driver] = $this->runAndDriver();
        $run->stops()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah 1', 'destination_type' => 'school',
            'sequence_order' => 1, 'small_portions' => 10, 'large_portions' => 10,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('nama kernet wajib diisi');

        app(DistributionWorkflow::class)->prepareLoad($run, $driver, [
            'vehicle_name' => 'Mobil Box', 'vehicle_plate' => 'B 1 MBG', 'driver_name' => 'Driver Uji',
        ]);
    }

    public function test_failed_destination_returns_all_portions_with_a_reason(): void
    {
        [$run, $driver] = $this->runAndDriver();
        $stop = $run->stops()->create([
            'route_name' => 'Rute 1', 'destination_name' => 'Sekolah Tutup', 'destination_type' => 'school',
            'sequence_order' => 1, 'small_portions' => 15, 'large_portions' => 25,
        ]);
        $workflow = app(DistributionWorkflow::class);
        $workflow->prepareLoad($run, $driver, [
            'vehicle_name' => 'Mobil Box', 'vehicle_plate' => 'B 2 MBG',
            'driver_name' => 'Driver Uji', 'kernet_name' => 'Kernet Uji',
        ]);
        $workflow->depart($run->fresh(), $driver, []);
        $workflow->arriveAtStop($run->fresh(), $stop->fresh(), $driver);
        $stop->update(['failure_reason' => 'Lokasi tutup dan penerima tidak dapat dihubungi.']);

        $workflow->failStop($run->fresh(), $stop->fresh(), $driver);

        $stop->refresh();
        $this->assertSame(DistributionStopStatus::Failed, $stop->status);
        $this->assertSame(15, $stop->returned_small_portions);
        $this->assertSame(25, $stop->returned_large_portions);
    }

    /** @return array{DistributionRun, User} */
    private function runAndDriver(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-DIST', 'name' => 'SPPG Distribusi', 'slug' => 'sppg-distribusi', 'is_active' => true,
        ]);
        $driver = User::factory()->create();
        $driver->givePermissionTo(Permission::findOrCreate('distribution.update'));
        $run = DistributionRun::query()->create([
            'sppg_unit_id' => $unit->id,
            'distribution_date' => today(),
            'menu_name_snapshot' => 'Menu Uji',
            'state' => DistributionRunState::Planned,
            'status' => OperationalReportStatus::Draft,
            'created_by' => $driver->id,
        ]);

        return [$run, $driver];
    }
}
