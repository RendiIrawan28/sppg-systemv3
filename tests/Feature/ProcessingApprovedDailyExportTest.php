<?php

use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('exports only completed batches approved by Kepala SPPG even when other batches remain draft', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PROCESS-EXPORT',
        'name' => 'SPPG Pengolahan',
        'slug' => 'sppg-pengolahan-export',
        'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Admin Pengujian',
        'email' => 'processing-export@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $makeBatch = function (string $product, string $state, string $status) use ($unit): ProcessingBatch {
        return ProcessingBatch::query()->create([
            'sppg_unit_id' => $unit->id,
            'production_date' => '2026-10-08',
            'product_name' => $product,
            'menu_name_snapshot' => $product,
            'state' => $state,
            'status' => $status,
        ]);
    };

    $approved = $makeBatch('Menu disetujui', 'completed', 'verified');
    $alsoApproved = $makeBatch('Menu disetujui kedua', 'completed', 'verified');
    $draft = $makeBatch('Menu masih draf', 'in_progress', 'draft');
    $waitingApproval = $makeBatch('Menu menunggu persetujuan', 'completed', 'division_approved');
    $makeBatch('Menu dibatalkan', 'cancelled', 'draft');

    $views = [];
    $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')->twice()->with('a4', 'landscape')->andReturnSelf();
    $pdf->shouldReceive('download')->twice()->andReturn(response('PDF', 200, ['content-type' => 'application/pdf']));
    Pdf::shouldReceive('loadView')->twice()->andReturnUsing(function (string $view, array $data) use (&$views, $pdf) {
        $views[$view] = $data;

        return $pdf;
    });

    $this->actingAs($actor)
        ->get("/processing-batches/{$approved->id}/monitoring-produksi.pdf")
        ->assertOk();

    Sanctum::actingAs($actor, ['mobile']);
    $this->getJson("/api/mobile/operational-modules/pengolahan/records/{$approved->id}")
        ->assertOk()
        ->assertJsonPath('data.capabilities.can_view_document', true);
    $this->getJson("/api/mobile/operational-modules/pengolahan/records/{$draft->id}")
        ->assertOk()
        ->assertJsonPath('data.capabilities.can_view_document', false);
    $this->get("/api/mobile/operational-modules/pengolahan/records/{$approved->id}/document?type=temperature")
        ->assertOk();

    foreach (['reports.processing-monitoring-production-pdf', 'reports.processing-temperature-monitoring-pdf'] as $view) {
        expect($views[$view]['batches']->pluck('id')->all())->toBe([$approved->id, $alsoApproved->id]);
    }

    $this->get("/api/mobile/operational-modules/pengolahan/records/{$draft->id}/document")
        ->assertForbidden();
    $this->get("/api/mobile/operational-modules/pengolahan/records/{$waitingApproval->id}/document")
        ->assertForbidden();
});

it('renders both daily processing PDFs when a draft batch exists on the same date', function (): void {
    $unit = SppgUnit::query()->create([
        'code' => 'SPPG-PDF-RENDER',
        'name' => 'SPPG Pengolahan PDF',
        'slug' => 'sppg-pengolahan-pdf',
        'is_active' => true,
    ]);
    $actor = User::query()->create([
        'name' => 'Admin PDF',
        'email' => 'processing-pdf@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);
    $approved = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => '2026-10-08',
        'product_name' => 'Menu disetujui',
        'menu_name_snapshot' => 'Menu disetujui',
        'state' => 'completed',
        'status' => 'verified',
    ]);
    ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => '2026-10-08',
        'product_name' => 'Menu draf',
        'menu_name_snapshot' => 'Menu draf',
        'state' => 'in_progress',
        'status' => 'draft',
    ]);

    $this->actingAs($actor);
    foreach (['monitoring-produksi.pdf', 'pemantauan-suhu.pdf'] as $document) {
        $response = $this->get("/processing-batches/{$approved->id}/{$document}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF');
    }
});
