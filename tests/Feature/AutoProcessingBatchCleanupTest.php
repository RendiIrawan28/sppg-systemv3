<?php

use App\Models\FieldDistributionPlan;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\FieldOperationalPlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-09-29 09:00:00')));

function autoProcessingCleanupContext(): array
{
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-AUTO-CLEAN', 'name' => 'SPPG Cleanup',
        'slug' => 'sppg-auto-clean', 'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Cleanup', 'email' => 'auto-clean@example.test',
        'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'plan_number' => 'RDL/AUTO-CLEAN/001',
        'plan_year' => 2026,
        'sequence_number' => 1,
        'distribution_date' => today(),
        'production_date' => today(),
        'menu_name_snapshot' => 'Nasi dan Ayam',
        'planned_small_portions' => 25,
        'planned_large_portions' => 25,
        'planned_total_portions' => 50,
        'status' => 'activated',
        'created_by' => $user->id,
    ]);
    $batch = app(FieldOperationalPlanGenerator::class)->generateProcessingBatch($plan, $user);

    return [$unit, $user, $plan->refresh(), $batch];
}

it('previews an empty generated batch without changing it', function (): void {
    [, , $plan, $batch] = autoProcessingCleanupContext();

    expect(Artisan::call('processing:cleanup-empty-auto-generated', [
        '--dry-run' => true, '--id' => $batch->id,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('AMAN UNTUK DIHAPUS')
        ->and($batch->fresh()->deleted_at)->toBeNull()
        ->and($plan->fresh()->processing_batch_id)->toBe($batch->id);

    expect(Artisan::call('processing:cleanup-empty-auto-generated', [
        '--id' => $batch->id,
    ]))->toBe(0);
    expect($batch->fresh()->deleted_at)->toBeNull();
});

it('soft deletes only a verified empty generated batch and detaches legacy links', function (): void {
    [$unit, $user, $plan, $batch] = autoProcessingCleanupContext();
    $session = PortioningSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'field_distribution_plan_id' => $plan->id,
        'processing_batch_id' => $batch->id,
        'portioning_date' => today(),
        'menu_name_snapshot' => $plan->menu_name_snapshot,
        'state' => 'planned', 'status' => 'draft',
        'created_by' => $user->id,
    ]);

    expect(Artisan::call('processing:cleanup-empty-auto-generated', [
        '--execute' => true, '--id' => $batch->id,
    ]))->toBe(0);
    expect(ProcessingBatch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and(ProcessingBatch::withTrashed()->findOrFail($batch->id)->deleted_at)->not->toBeNull()
        ->and($plan->fresh()->processing_batch_id)->toBeNull()
        ->and($session->fresh()->processing_batch_id)->toBeNull();
});

it('skips generated batches with real activity or a changed state', function (string $activity): void {
    [, $user, , $batch] = autoProcessingCleanupContext();

    match ($activity) {
        'material' => $batch->materialUsages()->create([
            'material_name' => 'Ayam', 'quantity' => 1, 'unit_name' => 'kg',
        ]),
        'temperature' => $batch->temperatureLogs()->create([
            'checkpoint' => 'final', 'temperature_celsius' => 75,
        ]),
        'documentation' => $batch->documentations()->create([
            'documentation_type' => 'finished_output', 'photo_path' => 'processing/test.jpg',
        ]),
        'history' => $batch->histories()->create([
            'actor_id' => $user->id, 'action' => 'tested',
        ]),
        'warehouse' => WarehouseWithdrawal::query()->create([
            'sppg_unit_id' => $batch->sppg_unit_id,
            'withdrawal_date' => today(),
            'division_code' => 'pengolahan',
            'reference_type' => 'processing_batch',
            'reference_id' => $batch->id,
            'status' => WarehouseWithdrawal::DRAFT,
            'taken_by' => $user->id,
        ]),
        'portioning_supply' => PortioningSession::query()->create([
            'sppg_unit_id' => $batch->sppg_unit_id,
            'portioning_date' => today(),
            'state' => 'planned', 'status' => 'draft',
        ])->supplies()->create([
            'source_type' => 'processing_batch', 'source_id' => $batch->id,
            'source_item_id' => $batch->id, 'supply_name' => 'Hasil uji',
            'quantity' => 1, 'unit_name' => 'pack',
        ]),
        'handover' => $batch->update(['portioning_handed_over_at' => now()]),
        'output' => $batch->update(['actual_output_quantity' => 10, 'actual_output_unit' => 'pack']),
        'completed' => $batch->update(['state' => 'completed']),
        'manual_note' => $batch->update(['notes' => 'Pekerjaan yang dicatat manual.']),
    };

    expect(Artisan::call('processing:cleanup-empty-auto-generated', [
        '--execute' => true, '--id' => $batch->id,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('LEWATI')
        ->and(ProcessingBatch::query()->whereKey($batch->id)->exists())->toBeTrue()
        ->and($batch->fresh()->deleted_at)->toBeNull();
})->with(['material', 'temperature', 'documentation', 'history', 'warehouse', 'portioning_supply', 'handover', 'output', 'completed', 'manual_note']);
