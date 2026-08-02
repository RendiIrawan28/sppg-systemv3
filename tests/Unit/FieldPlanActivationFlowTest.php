<?php

test('field plan activation only prepares distribution routes', function (): void {
    $workflow = file_get_contents(app_path('Services/FieldDistributionPlanWorkflow.php'));
    $legacyGenerator = 'app(FieldOperationalPlanGenerator::class)->'.'generate($plan, $actor)';

    expect($workflow)
        ->toContain('generateDistributionRuns($plan, $actor)')
        ->not->toContain($legacyGenerator)
        ->toContain("'processing_batch' => null")
        ->toContain("'portioning_session' => null");
});

test('field plan api explains the simplified operational handoff', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/FieldPlanController.php'));

    expect($controller)
        ->toContain('rute Distribusi telah disiapkan')
        ->toContain('Pengolahan dan Pemorsian dimulai manual')
        ->not->toContain('Batch Pengolahan, sesi Pemorsian, dan rute Distribusi telah disiapkan');
});

test('preparation empty state no longer requires prior warehouse verification', function (): void {
    $view = file_get_contents(resource_path('views/livewire/v3/preparation/index.blade.php'));

    expect($view)
        ->toContain('Belum ada pengambilan barang yang dicatat oleh Divisi Persiapan.')
        ->not->toContain('Belum ada pengambilan Persiapan yang diverifikasi Gudang.');
});

test('distribution route buttons remain connected to livewire actions', function (): void {
    $view = file_get_contents(resource_path('views/livewire/v3/operations/distribution-form.blade.php'));
    $component = file_get_contents(app_path('Livewire/V3/Operations/Form.php'));

    expect($view)
        ->toContain('wire:click="claimRoute"')
        ->toContain("wire:click=\"workflow('{{ \$action }}')\"")
        ->and($component)
        ->toContain('public function claimRoute(): void')
        ->toContain('public function workflow(string $action): void');
});
