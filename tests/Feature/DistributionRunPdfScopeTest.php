<?php

use App\Http\Controllers\DistributionRunPdfController;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\SppgUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('collects every route from the same distribution plan for one pdf', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PDF-DST',
        'name' => 'SPPG PDF Distribusi',
        'slug' => 'sppg-pdf-distribusi',
        'is_active' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'distribution_date' => today(),
        'service_date' => today(),
        'production_date' => today(),
        'status' => 'activated',
    ]);
    $first = DistributionRun::query()->create([
        'sppg_unit_id' => $unit->id,
        'field_distribution_plan_id' => $plan->id,
        'distribution_date' => today(),
        'route_name' => 'Rute 1',
    ]);
    DistributionRun::query()->create([
        'sppg_unit_id' => $unit->id,
        'field_distribution_plan_id' => $plan->id,
        'distribution_date' => today(),
        'route_name' => 'Rute 2',
    ]);
    DistributionRun::query()->create([
        'sppg_unit_id' => $unit->id,
        'distribution_date' => today(),
        'route_name' => 'Rute di luar rencana',
    ]);

    $method = new ReflectionMethod(DistributionRunPdfController::class, 'runsForExport');
    $runs = $method->invoke(new DistributionRunPdfController, $first, []);

    $runs->load(['sppgUnit', 'fieldDistributionPlan', 'stops', 'incidents.stop']);
    $html = view('reports.distribution-run-pdf', [
        'run' => $runs->first(),
        'runs' => $runs,
        'plan' => $runs->first()->fieldDistributionPlan,
    ])->render();

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('route_name')->all())->toBe(['Rute 1', 'Rute 2'])
        ->and($html)->toContain('LAPORAN KESELURUHAN RUTE DISTRIBUSI', 'Rute 1', 'Rute 2')
        ->not->toContain('Rute di luar rencana');
});

it('keeps a standalone legacy route exportable', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PDF-OLD',
        'name' => 'SPPG PDF Lama',
        'slug' => 'sppg-pdf-lama',
        'is_active' => true,
    ]);
    $run = DistributionRun::query()->create([
        'sppg_unit_id' => $unit->id,
        'distribution_date' => today(),
        'route_name' => 'Rute Lama',
    ]);

    $method = new ReflectionMethod(DistributionRunPdfController::class, 'runsForExport');
    $runs = $method->invoke(new DistributionRunPdfController, $run, []);

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->is($run))->toBeTrue();
});
