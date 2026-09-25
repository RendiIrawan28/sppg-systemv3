<?php

use App\Http\Controllers\MonitoringPhotoController;
use App\Models\AttendanceSession;
use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\CleaningArea;
use App\Models\CleaningChecklistItem;
use App\Models\CleaningChemicalUsage;
use App\Models\CleaningDocumentation;
use App\Models\CleaningFinding;
use App\Models\CleaningSession;
use App\Models\CleaningWasteRecord;
use App\Models\DistributionRun;
use App\Models\DistributionStop;
use App\Models\Division;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingChecklistItem;
use App\Models\WashingChemicalUsage;
use App\Models\WashingDeviation;
use App\Models\WashingDocumentation;
use App\Models\WashingMeasurement;
use App\Models\WashingSession;
use App\Models\WashingWasteRecord;
use App\Services\V3\OperationalMonitoringService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // Dedicated memory-only connection. No application migrations, resets, or seeders.
    $this->monitoringOriginalConnection = DB::getDefaultConnection();
    config(['database.connections.monitoring_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    DB::purge('monitoring_test');
    DB::setDefaultConnection('monitoring_test');
    expect(DB::connection()->getConfig('database'))->toBe(':memory:');
    foreach ([SppgUnit::class, User::class, Division::class, AttendanceSession::class, AttendanceWorkSchedule::class, AttendanceWorkScheduleAssignment::class,
        WashingSession::class, WashingChecklistItem::class, WashingWasteRecord::class, WashingDocumentation::class, WashingMeasurement::class, WashingDeviation::class, WashingChemicalUsage::class,
        CleaningArea::class, CleaningSession::class, CleaningChecklistItem::class, CleaningWasteRecord::class, CleaningDocumentation::class, CleaningFinding::class, CleaningChemicalUsage::class,
        FieldDistributionPlan::class, FieldDistributionPlanDestination::class, DistributionRun::class, DistributionStop::class] as $modelClass) {
        $model = new $modelClass;
        Schema::create($model->getTable(), function (Blueprint $table) use ($model) {
            $table->id();
            foreach (array_unique([...$model->getFillable(), 'deleted_at', 'created_at', 'updated_at']) as $field) {
                if ($field !== 'id') {
                    if (str_ends_with($field, '_id') || str_starts_with($field, 'is_') || in_array($field, ['sort_order'])) {
                        $table->integer($field)->nullable();
                    } else {
                        $table->text($field)->nullable();
                    }
                }
            }
        });
    }
    Schema::create('division_user', function (Blueprint $table) {
        $table->id();
        foreach (['user_id', 'division_id', 'sppg_unit_id', 'is_active', 'is_primary'] as $column) {
            $table->integer($column);
        }
        $table->string('position')->nullable();
        $table->timestamps();
    });
    $this->service = app(OperationalMonitoringService::class);
});

afterEach(function () {
    DB::purge('monitoring_test');
    DB::setDefaultConnection($this->monitoringOriginalConnection);
});

function monitoringFixture(string $model, array $data): int
{
    return DB::table((new $model)->getTable())->insertGetId($data);
}

it('paginates all washing reports while preserving summary scope and every checklist', function () {
    for ($i = 1; $i <= 18; $i++) {
        $id = monitoringFixture(WashingSession::class, ['sppg_unit_id' => 1, 'washing_date' => '2026-09-23', 'session_number' => 'WSH-'.$i, 'state' => 'washing', 'status' => 'draft', 'received_containers' => 10, 'washed_containers' => 0, 'clean_containers' => null, 'created_at' => '2026-09-25']);
        foreach ([true, false, null] as $value) {
            monitoringFixture(WashingChecklistItem::class, ['washing_session_id' => $id, 'item_name' => 'Periksa '.$i, 'is_passed' => $value, 'checked_at' => $value === null ? null : '2026-09-23 08:00:00']);
        }
    }
    monitoringFixture(WashingSession::class, ['sppg_unit_id' => 2, 'washing_date' => '2026-09-23', 'state' => 'ready', 'status' => 'verified', 'received_containers' => 999]);
    monitoringFixture(WashingSession::class, ['sppg_unit_id' => 1, 'washing_date' => '2026-09-24', 'state' => 'ready', 'status' => 'verified', 'received_containers' => 999]);
    DB::enableQueryLog();
    $data = $this->service->finalModule(1, '2026-09-23', 'washing');
    $queries = DB::getQueryLog();
    expect($data['pagination']->total())->toBe(18)->and($data['rows'])->toHaveCount(15)
        ->and((int) $data['cards'][2]['value'])->toBe(180)
        ->and($data['cards'][4]['value'])->toBe('Belum diisi')
        ->and($data['rows'][0]['sections']['Checklist'])->toHaveCount(3)
        ->and(collect($data['rows'][0]['sections']['Checklist'])->pluck('Hasil')->all())->toBe(['Terpenuhi', 'Tidak terpenuhi', 'Belum diperiksa']);
    expect(collect($queries)->every(fn ($query) => str_starts_with(strtolower($query['query']), 'select')))->toBeTrue();
    Paginator::currentPageResolver(fn () => 2);
    $pageTwo = $this->service->finalModule(1, '2026-09-23', 'washing');
    expect($pageTwo['rows'])->toHaveCount(3)->and((int) $pageTwo['cards'][2]['value'])->toBe(180);
    Paginator::currentPageResolver(fn () => 1);
});

it('preserves cleaning answers and report status independently', function () {
    $id = monitoringFixture(CleaningSession::class, ['sppg_unit_id' => 1, 'scheduled_date' => '2026-09-23', 'state' => 'ready', 'status' => 'draft']);
    foreach (['pass', 'fail', 'pending', 'na', null] as $answer) {
        monitoringFixture(CleaningChecklistItem::class, ['cleaning_session_id' => $id, 'item_name' => 'Snapshot lama', 'result' => $answer]);
    }
    $data = $this->service->finalModule(1, '2026-09-23', 'cleaning');
    expect($data['cards'][1]['value'])->toBe(0)
        ->and($data['rows'][0]['fields']['Laporan'])->toBe('Draft')
        ->and(collect($data['rows'][0]['sections']['Checklist'])->pluck('Hasil')->all())->toBe(['Terpenuhi', 'Tidak terpenuhi', 'Belum diisi', 'Tidak berlaku', 'Belum diisi']);
});

it('keeps unconfirmed and unrouted destinations without substituting planned beneficiaries', function () {
    $plan = monitoringFixture(FieldDistributionPlan::class, ['sppg_unit_id' => 1, 'distribution_date' => '2026-09-23', 'status' => 'draft']);
    foreach (['pending', 'confirmed'] as $status) {
        monitoringFixture(FieldDistributionPlanDestination::class, ['field_distribution_plan_id' => $plan, 'destination_name_snapshot' => 'Sekolah sama', 'confirmation_status' => $status, 'registered_beneficiaries' => 100, 'confirmed_beneficiaries' => 90, 'small_portions' => 40, 'large_portions' => 50]);
    }
    $route = monitoringFixture(DistributionRun::class, ['sppg_unit_id' => 1, 'field_distribution_plan_id' => $plan, 'route_name' => 'Rute 1', 'state' => 'departed']);
    monitoringFixture(DistributionStop::class, ['distribution_run_id' => $route, 'destination_name' => 'Sekolah sama', 'arrived_at' => '2026-09-23 09:20:00', 'status' => 'arrived']);
    $data = $this->service->finalModule(1, '2026-09-23', 'field-assistant');
    expect($data['cards'][1]['value'])->toBe(2)->and((int) $data['cards'][2]['value'])->toBe(90)
        ->and($data['cards'][6]['value'])->toBe(1)->and($data['cards'][6]['detail'])->toContain('Dalam Pengantaran')
        ->and($data['rows'][0]['sections']['Tujuan / kunjungan'])->toHaveCount(2)
        ->and($data['rows'][0]['sections']['Kedatangan aktual per tujuan'])->toHaveCount(1)
        ->and($data['rows'][0]['sections']['Tujuan / kunjungan'][0]['Penerima terkonfirmasi'])->toBe('Belum dikonfirmasi')
        ->and($data['rows'][0]['sections']['Tujuan / kunjungan'][0]['Rute'])->toBe('Belum ditentukan');
});

it('counts scheduled people and preserves multiple overnight and open sessions', function () {
    $division = monitoringFixture(Division::class, ['name' => 'Persiapan', 'is_active' => 1]);
    for ($i = 1; $i <= 3; $i++) {
        $id = monitoringFixture(User::class, ['name' => 'Petugas '.$i, 'is_active' => 1]);
        DB::table('division_user')->insert(['user_id' => $id, 'division_id' => $division, 'sppg_unit_id' => 1, 'is_active' => 1, 'is_primary' => 1]);
    }
    monitoringFixture(User::class, ['name' => 'Akun tanpa divisi', 'is_active' => 1]);
    monitoringFixture(AttendanceWorkSchedule::class, ['sppg_unit_id' => 1, 'division_id' => $division, 'is_active' => 1, 'is_default' => 1, 'work_days' => '[1,2,3,4,5]', 'effective_from' => '2026-09-01']);
    monitoringFixture(AttendanceSession::class, ['sppg_unit_id' => 1, 'user_id' => 1, 'work_date' => '2026-09-23', 'check_in_at' => '2026-09-23 20:00:00', 'check_out_at' => '2026-09-24 02:00:00', 'status' => 'present']);
    monitoringFixture(AttendanceSession::class, ['sppg_unit_id' => 1, 'user_id' => 1, 'work_date' => '2026-09-23', 'check_in_at' => '2026-09-23 08:00:00', 'status' => 'present']);
    monitoringFixture(AttendanceSession::class, ['sppg_unit_id' => 1, 'user_id' => 2, 'work_date' => '2026-09-23', 'status' => 'sick']);
    $data = $this->service->finalModule(1, '2026-09-23', 'attendance');
    expect(array_column($data['cards'], 'value'))->toBe([3, 1, 1, 1, 1, 3])
        ->and($data['rows'])->toHaveCount(3)
        ->and($data['rows'][1]['fields']['Durasi'])->toBe('Belum selesai')
        ->and($data['rows'][2]['fields']['Durasi'])->toBe('360 menit');
    expect($this->service->finalModule(1, '2026-09-24', 'attendance')['rows'])->toBe([]);
});

it('does not grow queries with the number of cleaning checklists', function () {
    $id = monitoringFixture(CleaningSession::class, ['sppg_unit_id' => 1, 'scheduled_date' => '2026-09-23', 'state' => 'ready', 'status' => 'draft']);
    monitoringFixture(CleaningChecklistItem::class, ['cleaning_session_id' => $id, 'item_name' => 'Item 1', 'result' => 'pass']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->service->finalModule(1, '2026-09-23', 'cleaning');
    $smallCount = count(DB::getQueryLog());
    for ($i = 2; $i <= 30; $i++) {
        monitoringFixture(CleaningChecklistItem::class, ['cleaning_session_id' => $id, 'item_name' => 'Item '.$i, 'result' => 'pending']);
    }
    DB::flushQueryLog();
    $data = $this->service->finalModule(1, '2026-09-23', 'cleaning');
    $largeCount = count(DB::getQueryLog());
    if (getenv('MONITORING_QUERY_AUDIT')) {
        fwrite(STDERR, "Monitoring cleaning query count: 1 item={$smallCount}, 30 items={$largeCount}\n");
    }
    expect($largeCount)->toBe($smallCount)->and($data['rows'][0]['sections']['Checklist'])->toHaveCount(30);
});

it('authorizes monitoring photos and rejects other tenants and unrelated child ids', function () {
    monitoringFixture(SppgUnit::class, ['name' => 'SPPG test', 'is_active' => 1]);
    $first = monitoringFixture(WashingSession::class, ['sppg_unit_id' => 1]);
    $second = monitoringFixture(WashingSession::class, ['sppg_unit_id' => 2]);
    $photo = monitoringFixture(WashingDocumentation::class, ['washing_session_id' => $first, 'photo_path' => 'monitoring-test/photo.jpg']);
    $foreignPhoto = monitoringFixture(WashingDocumentation::class, ['washing_session_id' => $second, 'photo_path' => 'monitoring-test/foreign.jpg']);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_active = true;
    $user->is_super_admin = false;
    $user->shouldReceive('can')->with('monitoring_operasional.view')->andReturn(true);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);
    $controller = app(MonitoringPhotoController::class);
    Storage::fake('public');
    Storage::disk('public')->put('monitoring-test/photo.jpg', 'test image');
    expect($controller($request, 'washing', $first, 'documentation', $photo)->getStatusCode())->toBe(200);
    expect(fn () => $controller($request, 'washing', $second, 'documentation', $foreignPhoto))->toThrow(ModelNotFoundException::class);
    expect(fn () => $controller($request, 'washing', $first, 'documentation', $foreignPhoto))->toThrow(ModelNotFoundException::class);
    $denied = Mockery::mock(User::class)->makePartial();
    $denied->is_super_admin = false;
    $denied->shouldReceive('can')->andReturn(false);
    $request->setUserResolver(fn () => $denied);
    try {
        $controller($request, 'washing', $first, 'documentation', $photo);
        $this->fail('Unauthorized photo should be rejected');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('renders readable monitoring details without source permissions', function () {
    $id = monitoringFixture(CleaningSession::class, ['sppg_unit_id' => 1, 'scheduled_date' => '2026-09-23', 'state' => 'ready', 'status' => 'draft', 'session_number' => 'CLN-test']);
    monitoringFixture(CleaningChecklistItem::class, ['cleaning_session_id' => $id, 'item_name' => 'Bersihkan meja', 'result' => 'pending']);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_super_admin = false;
    $user->shouldReceive('can')->andReturn(false);
    auth()->setUser($user);
    $html = view('livewire.v3.monitoring.partials.final-module', ['activeTab' => 'cleaning', 'workDate' => '2026-09-23', 'finalModule' => $this->service->finalModule(1, '2026-09-23', 'cleaning')])->render();
    expect($html)->toContain('Bersihkan meja')->toContain('Belum diisi')->not->toContain('Buka modul sumber');
});

it('does not link operational edit forms as record details', function () {
    $id = monitoringFixture(CleaningSession::class, ['sppg_unit_id' => 1, 'scheduled_date' => '2026-09-23', 'state' => 'ready', 'status' => 'draft', 'session_number' => 'CLN-test']);
    $user = Mockery::mock(User::class)->makePartial();
    $user->is_super_admin = false;
    $user->shouldReceive('can')->with('cleaning.view')->andReturn(true);
    auth()->setUser($user);
    $html = view('livewire.v3.monitoring.partials.final-module', ['activeTab' => 'cleaning', 'workDate' => '2026-09-23', 'finalModule' => $this->service->finalModule(1, '2026-09-23', 'cleaning')])->render();
    expect($html)->toContain('Buka modul sumber')->not->toContain('Lihat detail sumber');
});

it('keeps details isolated to the active tab and groups waste by unit', function () {
    $id = monitoringFixture(WashingSession::class, ['sppg_unit_id' => 1, 'washing_date' => '2026-09-23', 'state' => 'washing', 'status' => 'draft']);
    foreach ([['kg', 5], ['porsi', 20]] as [$unit, $quantity]) {
        monitoringFixture(WashingWasteRecord::class, ['washing_session_id' => $id, 'unit' => $unit, 'quantity' => $quantity]);
    }
    $unit = new SppgUnit;
    $unit->id = 1;
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = $this->service->forTab($unit, '2026-09-23', 'washing');
    $queries = implode(' ', array_column(DB::getQueryLog(), 'query'));
    expect($queries)->not->toContain('cleaning_sessions')->not->toContain('attendance_sessions')->not->toContain('field_distribution_plans')
        ->and(end($data['finalModule']['cards'])['detail'])->toContain('5 kg')->toContain('20 porsi')->not->toContain('25');
});
