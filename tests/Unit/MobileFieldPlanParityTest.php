<?php

use App\Support\Mobile\MobileWorkspaceRegistry;
use Illuminate\Support\Facades\Route;

it('exposes the complete mobile distribution plan workflow', function (): void {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->all();

    expect($routes)
        ->toContain('GET|HEAD api/mobile/field-plans/options')
        ->toContain('POST api/mobile/field-plans')
        ->toContain('PUT api/mobile/field-plans/{plan}')
        ->toContain('DELETE api/mobile/field-plans/{plan}')
        ->toContain('POST api/mobile/field-plans/{plan}/refresh-beneficiaries')
        ->toContain('GET|HEAD api/mobile/field-plans/{plan}/readiness')
        ->toContain('POST api/mobile/field-plans/{plan}/activate')
        ->toContain('GET|HEAD api/mobile/field-plans/{fieldDistributionPlan}/document');
});

it('limits the field assistant operational registry to reports and incidents', function (): void {
    $source = file_get_contents(app_path('Support/Mobile/MobileWorkspaceRegistry.php'));

    expect($source)
        ->toContain("UserRole::AsistenLapangan->value => ['lapangan-insiden', 'lapangan-laporan']")
        ->not->toContain("UserRole::AsistenLapangan->value => ['lapangan-konfirmasi'");

    $definitions = app(MobileWorkspaceRegistry::class)->definitions();
    expect($definitions)->toHaveKeys(['lapangan-insiden', 'lapangan-laporan']);
});

it('keeps obsolete departure and arrival confirmation out of mobile planning', function (): void {
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/FieldPlanScreens.kt'));
    $updateService = file_get_contents(app_path('Services/MobileFieldPlanUpdateService.php'));

    expect($screen)
        ->not->toContain('label = { Text("Berangkat") }')
        ->not->toContain('label = { Text("Tiba") }')
        ->and($updateService)
        ->toContain("'planned_departure_time' => null")
        ->toContain("'planned_arrival_time' => null");
});

it('uses a dropdown instead of free text for mobile distribution routes', function (): void {
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/FieldPlanScreens.kt'));

    expect($screen)
        ->toContain('ExposedDropdownMenuBox(')
        ->toContain('(1..maxOf(10, destinations.size)).map { "Rute $it" }')
        ->toContain('placeholder = { Text("Pilih rute") }')
        ->not->toContain('onValueChange = { onDestinationChange(destination.copy(routeName = it)) }');
});

it('shows a direct activation action for editable mobile plans', function (): void {
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/FieldPlanScreens.kt'));

    expect($screen)
        ->toContain('Text("Aktifkan rencana")')
        ->toContain('activationRequested = true')
        ->not->toContain('Text("Periksa kesiapan")');
});
