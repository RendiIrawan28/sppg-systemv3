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

it('routes the approved preparation report through the mobile document controller', function (): void {
    $source = file_get_contents(app_path('Http/Controllers/Api/MobileDocumentController.php'));

    expect($source)
        ->toContain("'persiapan' => app(PreparationSessionCalculationPdfController::class)")
        ->toContain('PreparationSessionCalculationPdfController');
});

it('creates processing manually while portioning still starts from an active plan on mobile', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    expect($definitions['pengolahan']['allow_create'])->toBeTrue()
        ->and(collect($definitions['pengolahan']['fields'])->firstWhere('name', 'production_date')['create_only'])->toBeTrue()
        ->and(collect($definitions['pengolahan']['fields'])->firstWhere('name', 'product_name')['create_only'])->toBeTrue()
        ->and(collect($definitions['pengolahan']['fields'])->firstWhere('name', 'field_distribution_plan_id'))->toBeNull()
        ->and($definitions['pemorsian']['allow_create'])->toBeTrue()
        ->and(collect($definitions['pemorsian']['fields'])->firstWhere('name', 'field_distribution_plan_id'))
        ->not->toBeNull();

    foreach (['distribusi', 'pencucian', 'kebersihan'] as $slug) {
        expect($definitions[$slug]['allow_create'] ?? true)->toBeFalse()
            ->and($definitions[$slug]['allow_delete'] ?? true)->toBeFalse();
    }

    expect($definitions['pengolahan']['allow_delete'] ?? true)->toBeFalse()
        ->and($definitions['pemorsian']['allow_delete'] ?? true)->toBeFalse();
});

it('exposes workflow modules needed by field operations', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    expect(array_keys($definitions))
        ->toContain('gudang')
        ->toContain('gudang-stok-awal')
        ->toContain('gudang-stok')
        ->toContain('gudang-penyesuaian')
        ->toContain('gudang-pengambilan')
        ->toContain('gudang-retur')
        ->toContain('pengambilan-ompreng')
        ->toContain('ba-limbah-persiapan')
        ->toContain('ba-limbah-pencucian')
        ->toContain('ba-limbah-kebersihan')
        ->toContain('lapangan-laporan')
        ->not->toContain('hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian');
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
