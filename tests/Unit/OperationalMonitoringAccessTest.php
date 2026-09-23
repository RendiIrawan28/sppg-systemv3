<?php

use App\Enums\UserRole;
use App\Livewire\V3\Monitoring\Landing;
use App\Livewire\V3\Monitoring\Operational;
use App\Models\WarehouseWithdrawalItem;
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
        ->toContain("COALESCE(target_small_portions, 0)")
        ->toContain("COALESCE(actual_small_portions, 0)")
        ->and($view)
        ->toContain("partials.portioning")
        ->toContain("partials.distribution");
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
