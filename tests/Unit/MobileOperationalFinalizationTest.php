<?php

use App\Models\PreparationOutput;
use App\Support\Mobile\MobileWorkspaceRegistry;
use Illuminate\Support\Facades\Route;

it('registers mobile workflow and document endpoints', function (): void {
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri())->all();

    expect($uris)
        ->toContain('api/mobile/field-plans/{fieldDistributionPlan}/document')
        ->toContain('api/mobile/operational-modules/{module}/records/{record}/document')
        ->toContain('api/mobile/operational-modules/{module}/records/{record}/actions/{action}')
        ->toContain('api/mobile/operational-modules/{module}/records/{record}/relations/{relation}/{item}/actions/{action}');
});

it('locks automatically generated operational sessions from generic mobile creation', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    foreach (['pengolahan', 'pemorsian', 'distribusi', 'pencucian', 'kebersihan'] as $slug) {
        expect($definitions[$slug]['allow_create'] ?? true)->toBeFalse()
            ->and($definitions[$slug]['allow_delete'] ?? true)->toBeFalse();
    }
});

it('exposes workflow modules needed by field operations', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    expect(array_keys($definitions))
        ->toContain('gudang')
        ->toContain('gudang-stok')
        ->toContain('gudang-pengambilan')
        ->toContain('gudang-retur')
        ->toContain('hasil-persiapan')
        ->toContain('hasil-persiapan-pengolahan')
        ->toContain('hasil-persiapan-pemorsian')
        ->toContain('pengambilan-ompreng')
        ->toContain('ba-limbah-persiapan')
        ->toContain('ba-limbah-pencucian')
        ->toContain('ba-limbah-kebersihan')
        ->toContain('lapangan-laporan');
});

it('blocks expired preparation output from being offered to another division', function (): void {
    $output = new PreparationOutput([
        'target_division' => 'processing',
        'available_quantity' => 10,
        'state' => PreparationOutput::AVAILABLE,
        'expires_at' => now()->subMinute(),
    ]);

    expect($output->isAvailableFor('processing'))->toBeFalse();
});
