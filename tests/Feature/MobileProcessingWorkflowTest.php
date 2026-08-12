<?php

use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
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
