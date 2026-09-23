<?php

use App\Enums\UserRole;
use App\Livewire\V3\Monitoring\Landing;
use App\Livewire\V3\Monitoring\Operational;
use App\Models\DistributionRun;
use App\Models\PortioningSession;
use App\Models\WarehouseWithdrawalItem;
use App\Services\V3\OperationalMonitoringService;
use App\Support\AccessControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

it('grants operational monitoring only to the agreed management roles', function (): void {
    expect(AccessControl::permissionsForRole(UserRole::KepalaSppg->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AdminSppg->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AhliGizi->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AsistenLapangan->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::PengawasKeuangan->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole('akuntan'))
        ->toContain('monitoring_operasional.view');

    expect(AccessControl::permissionsForRole(UserRole::StafGudang->value))
        ->not->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::PetugasPersiapan->value))
        ->not->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::Satpam->value))
        ->not->toContain('monitoring_operasional.view');
});

it('uses the actual warehouse withdrawal foreign key for monitoring queries', function (): void {
    expect((new WarehouseWithdrawalItem)->withdrawal()->getForeignKeyName())
        ->toBe('warehouse_withdrawal_id');
});

it('keeps monitoring tabs date filtered and read only', function (): void {
    $component = file_get_contents(app_path('Livewire/V3/Monitoring/Operational.php'));
    $service = file_get_contents(app_path('Services/V3/OperationalMonitoringService.php'));

    expect($component)
        ->toContain("['overview', 'warehouse', 'preparation', 'processing', 'portioning', 'distribution']")
        ->and($service)
        ->toContain("whereDate('preparation_date', \$date)")
        ->toContain("whereDate('production_date', \$date)")
        ->toContain('->forDate($date)')
        ->toContain('received_weight_kg')
        ->toContain('clean_weight_kg')
        ->toContain('waste_weight_kg')
        ->not->toContain('->save(')
        ->not->toContain('->update(')
        ->not->toContain('->create(');
});

it('loads stage four operational relations without per-row queries', function (): void {
    $service = file_get_contents(app_path('Services/V3/OperationalMonitoringService.php'));
    $view = file_get_contents(resource_path('views/livewire/v3/monitoring/operational.blade.php'));

    expect($service)
        ->toContain("->with(['routeAllocations', 'routeRecords', 'leftoverRecords', 'petugas'])")
        ->toContain("->with(['stops', 'documentations', 'petugas'])")
        ->toContain('COALESCE(target_small_portions, 0)')
        ->toContain('COALESCE(actual_small_portions, 0)')
        ->and($view)
        ->toContain('partials.portioning')
        ->toContain('partials.distribution');
});

it('uses the source model totals for portioning and distribution', function (): void {
    $portioning = new PortioningSession([
        'target_small_portions' => 320,
        'target_large_portions' => 480,
        'actual_small_portions' => 315,
        'actual_large_portions' => 475,
    ]);
    $distribution = new DistributionRun([
        'loaded_small_portions' => 320,
        'loaded_large_portions' => 480,
        'delivered_small_portions' => 310,
        'delivered_large_portions' => 470,
    ]);

    expect($portioning->target_total)->toBe(800)
        ->and($portioning->actual_total)->toBe(790)
        ->and($distribution->loaded_total)->toBe(800)
        ->and($distribution->delivered_total)->toBe(780);
});

it('filters stage four source queries by their operational dates', function (): void {
    $portioning = PortioningSession::query()->forDate('2026-08-24');
    $distribution = DistributionRun::query()->forDate('2026-08-25');

    expect($portioning->toSql())->toContain('portioning_date')
        ->and($portioning->getBindings())->toContain('2026-08-24')
        ->and($distribution->toSql())->toContain('distribution_date')
        ->and($distribution->getBindings())->toContain('2026-08-25');
});

it('does not combine portioning leftovers across different units', function (): void {
    $service = new OperationalMonitoringService;
    $method = new ReflectionMethod($service, 'summarizeQuantities');
    $result = $method->invoke($service, [
        ['quantity' => 5.0, 'unit' => 'kg'],
        ['quantity' => 20.0, 'unit' => 'porsi'],
        ['quantity' => 2.0, 'unit' => 'tray'],
    ]);

    expect($result[0])->toBe('3 satuan')
        ->and($result[1])->toContain('5 kg')
        ->toContain('20 porsi')
        ->toContain('2 tray')
        ->not->toContain('27');
});

it('provides stage four empty states and permission-aware detail links', function (): void {
    $portioning = file_get_contents(resource_path('views/livewire/v3/monitoring/partials/portioning.blade.php'));
    $distribution = file_get_contents(resource_path('views/livewire/v3/monitoring/partials/distribution.blade.php'));

    expect($portioning)
        ->toContain('Belum ada data Pemorsian')
        ->toContain("can('portioning.view')")
        ->and($distribution)
        ->toContain('Belum ada data Distribusi')
        ->toContain("can('distribution.view')")
        ->toContain('$stop[\'actual_total\'] ?? $stop[\'planned_total\']');
});

it('registers separate monitoring landing and daily routes', function (): void {
    expect(Route::getRoutes()->getByName('v3.monitoring.index')?->uri())
        ->toBe('v3/monitoring-operasional')
        ->and(Route::getRoutes()->getByName('v3.monitoring.operational')?->uri())
        ->toBe('v3/monitoring-operasional/harian');
});

it('falls back safely when monitoring tab or date query is invalid', function (): void {
    Carbon::setTestNow('2026-09-23 10:00:00');

    $operational = new Operational;
    $operational->activeTab = 'invalid-tab';
    $operational->workDate = 'not-a-date';
    $operational->refreshData();

    $landing = new Landing;
    $landing->workDate = '31-02-2026';
    $landing->refreshData();

    expect($operational->activeTab)->toBe('overview')
        ->and($operational->workDate)->toBe('2026-09-23')
        ->and($landing->workDate)->toBe('2026-09-23');
});

it('accepts the stage four monitoring tabs', function (): void {
    $operational = new Operational;

    $operational->activeTab = 'portioning';
    $operational->refreshData();
    expect($operational->activeTab)->toBe('portioning');

    $operational->activeTab = 'distribution';
    $operational->refreshData();
    expect($operational->activeTab)->toBe('distribution');
});

it('uses the lightweight summary method on the monitoring landing', function (): void {
    $landing = file_get_contents(app_path('Livewire/V3/Monitoring/Landing.php'));
    $service = file_get_contents(app_path('Services/V3/OperationalMonitoringService.php'));

    expect($landing)
        ->toContain('->summaryFor($unit, $this->workDate)')
        ->not->toContain('->for($unit, $this->workDate)')
        ->and($service)
        ->toContain('public function summaryFor(');
});
