<?php

use App\Livewire\V3\Processing\Index;
use App\Models\FieldDistributionPlan;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Services\FieldOperationalPlanGenerator;
use App\Services\PreparationOutputService;
use App\Services\ProcessingWorkflow;
use App\Services\WarehouseWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('starts processing from an active plan before any material is taken', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-START-FIRST',
        'name' => 'SPPG Mulai Dulu',
        'slug' => 'sppg-mulai-dulu',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Mulai Produksi',
        'email' => 'mulai-produksi@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'plan_number' => 'RDL/START/0001',
        'plan_year' => 2026,
        'sequence_number' => 1,
        'distribution_date' => today(),
        'production_date' => today(),
        'menu_name_snapshot' => 'Nasi Kuning',
        'planned_total_portions' => 100,
        'status' => 'activated',
        'created_by' => $user->id,
    ]);
    Sanctum::actingAs($user, ['mobile']);

    $this->postJson('/api/mobile/operational-modules/pengolahan/records', [
        'fields' => ['field_distribution_plan_id' => $plan->id],
    ])->assertCreated()
        ->assertJsonPath('data.state', 'in_progress');

    $batch = ProcessingBatch::query()->sole();
    expect($batch->field_distribution_plan_id)->toBe($plan->id)
        ->and($batch->materialUsages()->count())->toBe(0)
        ->and($batch->started_at)->not->toBeNull();

    expect(fn () => app(ProcessingWorkflow::class)->complete($batch, $user))
        ->toThrow(ValidationException::class);

    $sameBatch = app(FieldOperationalPlanGenerator::class)->generateProcessingBatch($plan, $user);
    expect($sameBatch->id)->toBe($batch->id)
        ->and(ProcessingBatch::query()->count())->toBe(1);
});

it('allows an empty active production to be cancelled but blocks warehouse pickup before start', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-CANCEL',
        'name' => 'SPPG Pembatalan',
        'slug' => 'sppg-pembatalan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Pembatalan',
        'email' => 'pembatalan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Uji',
        'product_name' => 'Menu Uji',
        'target_output_quantity' => 10,
        'target_output_unit' => 'porsi',
        'state' => 'planned',
        'status' => 'draft',
    ]);

    expect(fn () => app(WarehouseWithdrawalService::class)->createMobileDraft(
        $unit->id,
        'pengolahan',
        'record:'.$batch->id,
        null,
        null,
        null,
        $user,
    ))->toThrow(ValidationException::class);

    $batch = app(ProcessingWorkflow::class)->start($batch, $user);
    $cancelled = app(ProcessingWorkflow::class)->cancel($batch, $user, 'Salah memilih rencana produksi');

    expect($cancelled->state->value)->toBe('cancelled')
        ->and($cancelled->notes)->toContain('Salah memilih rencana produksi');
});

it('starts the same production-first flow from the website workspace', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-WEB-START',
        'name' => 'SPPG Website Pengolahan',
        'slug' => 'sppg-web-pengolahan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Website Pengolahan',
        'email' => 'web-pengolahan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $plan = FieldDistributionPlan::query()->create([
        'sppg_unit_id' => $unit->id,
        'plan_number' => 'RDL/WEB/0001',
        'plan_year' => 2026,
        'sequence_number' => 1,
        'distribution_date' => today(),
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Website',
        'planned_total_portions' => 75,
        'status' => 'activated',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedPlanId', (string) $plan->id)
        ->call('startSelectedPlan')
        ->assertHasNoErrors()
        ->assertSet('selectedId', ProcessingBatch::query()->sole()->id);

    expect(ProcessingBatch::query()->sole()->state->value)->toBe('in_progress')
        ->and(ProcessingBatch::query()->sole()->materialUsages()->count())->toBe(0);
});

it('reserves preparation output immediately after an active production takes it', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PREP-INPUT',
        'name' => 'SPPG Input Persiapan',
        'slug' => 'sppg-input-persiapan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Input Persiapan',
        'email' => 'input-persiapan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $warehouseWithdrawal = WarehouseWithdrawal::query()->create([
        'sppg_unit_id' => $unit->id,
        'withdrawal_date' => today(),
        'division_code' => 'persiapan',
        'status' => WarehouseWithdrawal::WAITING,
        'taken_by' => $user->id,
    ]);
    $preparation = PreparationSession::query()->create([
        'sppg_unit_id' => $unit->id,
        'warehouse_withdrawal_id' => $warehouseWithdrawal->id,
        'session_number' => 'PS/INPUT/0001',
        'preparation_date' => today(),
        'state' => 'completed',
        'status' => 'draft',
        'petugas_id' => $user->id,
    ]);
    $output = PreparationOutput::query()->create([
        'sppg_unit_id' => $unit->id,
        'preparation_session_id' => $preparation->id,
        'output_name' => 'Wortel Bersih',
        'quantity' => 10,
        'available_quantity' => 10,
        'unit_snapshot' => 'kg',
        'target_division' => 'processing',
        'state' => PreparationOutput::AVAILABLE,
        'created_by' => $user->id,
    ]);
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Menu Input Persiapan',
        'product_name' => 'Menu Input Persiapan',
        'target_output_quantity' => 10,
        'target_output_unit' => 'porsi',
        'state' => 'in_progress',
        'status' => 'draft',
        'started_at' => now(),
    ]);

    $taken = app(PreparationOutputService::class)->requestWithdrawal($output, $user, [
        'destination_division' => 'processing',
        'processing_batch_id' => $batch->id,
        'requested_quantity' => 4,
    ]);

    expect($taken->status)->toBe(PreparationOutputWithdrawal::WAITING)
        ->and((float) $output->refresh()->available_quantity)->toBe(6.0)
        ->and(app(ProcessingWorkflow::class)->canCancel($batch->refresh()))->toBeFalse();

    Sanctum::actingAs($user, ['mobile']);
    $response = $this->getJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}")
        ->assertOk();
    $section = collect($response->json('data.sections'))
        ->firstWhere('key', 'preparationOutputWithdrawals');

    expect($section)->not->toBeNull()
        ->and($section['title'])->toBe('Hasil Persiapan digunakan')
        ->and($section['can_create'])->toBeFalse()
        ->and($section['items'])->toHaveCount(1)
        ->and($section['items'][0]['title'])->toBe('Wortel Bersih')
        ->and(collect($section['items'][0]['fields'])->firstWhere('key', 'used_quantity')['value'])->toBe('4')
        ->and(collect($section['items'][0]['fields'])->firstWhere('key', 'verification_status_label')['value'])->toBe('Menunggu pengecekan');
});

it('records finished output only while processing is active and synchronizes the batch total', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PROCESSING',
        'name' => 'SPPG Pengolahan',
        'slug' => 'sppg-pengolahan',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Petugas Pengolahan',
        'email' => 'pengolahan@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    Sanctum::actingAs($user, ['mobile']);

    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Nasi Kuning',
        'product_name' => 'Nasi Kuning',
        'target_output_quantity' => 10,
        'target_output_unit' => 'loyang',
        'state' => 'in_progress',
        'status' => 'draft',
        'petugas_id' => $user->id,
        'petugas_name_snapshot' => $user->name,
    ]);
    $photo = 'data:image/png;base64,'.base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));

    foreach ([6, 4] as $index => $quantity) {
        $this->postJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}/relations/documentations", [
            'fields' => [
                'caption' => 'Hasil '.($index + 1),
                'output_quantity' => $quantity,
                'output_unit' => 'loyang',
                'captured_at' => now()->toIso8601String(),
            ],
            'files' => ['photo_path' => $photo],
        ])->assertCreated();
    }

    expect((float) $batch->refresh()->actual_output_quantity)->toBe(10.0)
        ->and($batch->actual_output_unit)->toBe('loyang');

    $batch->forceFill(['state' => 'completed'])->save();
    $this->postJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}/relations/documentations", [
        'fields' => [
            'caption' => 'Tidak boleh',
            'output_quantity' => 1,
            'output_unit' => 'loyang',
            'captured_at' => now()->toIso8601String(),
        ],
        'files' => ['photo_path' => $photo],
    ])->assertForbidden();
});
