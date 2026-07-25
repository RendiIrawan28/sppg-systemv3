<?php

use App\Enums\UserRole;
use App\Models\FieldDailyReport;
use App\Models\FieldIncident;
use App\Models\ProcessingBatch;
use App\Models\SppgUnit;
use App\Models\StockReceipt;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('returns only the workspace assigned to an operational division role', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::PetugasPengolahan);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'pengolahan')
        ->assertJsonPath('data.0.label', 'Pengolahan');
});

it('maps each operational role to its own mobile workspace', function (UserRole $role, string $slug, int $count): void {
    [$user, $token] = mobileOperationalUser($role);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonCount($count, 'data')
        ->assertJsonPath('data.0.slug', $slug);
})->with([
    'staf gudang' => [UserRole::StafGudang, 'gudang', 4],
    'petugas persiapan' => [UserRole::PetugasPersiapan, 'persiapan', 1],
    'kepala persiapan' => [UserRole::KepalaDivisiPersiapan, 'persiapan', 1],
    'petugas pengolahan' => [UserRole::PetugasPengolahan, 'pengolahan', 1],
    'kepala pengolahan' => [UserRole::KepalaDivisiPengolahan, 'pengolahan', 1],
    'petugas pemorsian' => [UserRole::PetugasPemorsian, 'pemorsian', 1],
    'kepala pemorsian' => [UserRole::KepalaDivisiPemorsian, 'pemorsian', 1],
    'petugas distribusi' => [UserRole::PetugasDistribusi, 'distribusi', 1],
    'kepala distribusi' => [UserRole::KepalaDivisiDistribusi, 'distribusi', 1],
    'petugas pencucian' => [UserRole::PetugasPencucian, 'pencucian', 1],
    'kepala pencucian' => [UserRole::KepalaDivisiPencucian, 'pencucian', 1],
    'petugas kebersihan' => [UserRole::PetugasKebersihan, 'kebersihan', 1],
    'kepala kebersihan' => [UserRole::KepalaDivisiKebersihan, 'kebersihan', 1],
]);

it('returns incident and daily report workspaces for the field assistant', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::AsistenLapangan);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'lapangan-insiden')
        ->assertJsonPath('data.1.slug', 'lapangan-laporan');
});

it('lists field incidents and shows the generated daily report detail', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::AsistenLapangan);
    $unit = SppgUnit::query()->firstOrFail();
    $incident = FieldIncident::query()->create([
        'sppg_unit_id' => $unit->id,
        'incident_date' => today(),
        'division_code' => 'distribution',
        'category' => 'delivery',
        'severity' => 'medium',
        'title' => 'Akses tujuan terhambat',
        'description' => 'Jalan menuju sekolah ditutup sementara.',
        'location' => 'Sekolah Harapan',
        'created_by' => $user->id,
    ]);
    $report = FieldDailyReport::query()->create([
        'sppg_unit_id' => $unit->id,
        'report_date' => today(),
        'planned_destinations' => 3,
        'completed_destinations' => 2,
        'delivered_portions' => 150,
        'operational_summary' => 'Dua dari tiga tujuan selesai.',
        'prepared_by' => $user->id,
    ]);
    $report->divisions()->create([
        'division_code' => 'distribution',
        'division_name' => 'Distribusi',
        'total_records' => 1,
        'verified_records' => 1,
        'completion_status' => 'completed',
    ]);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules/lapangan-insiden/records')
        ->assertOk()
        ->assertJsonPath('data.0.id', $incident->id)
        ->assertJsonPath('data.0.title', 'Akses tujuan terhambat');

    $this->withToken($token)
        ->getJson("/api/mobile/operational-modules/lapangan-laporan/records/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Dua dari tiga tujuan selesai.')
        ->assertJsonPath('data.sections.0.title', 'Ringkasan enam divisi')
        ->assertJsonPath('data.sections.0.items.0.title', 'Distribusi');
});

it('lists and shows generic processing records for the assigned division', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::PetugasPengolahan);
    $unit = SppgUnit::query()->firstOrFail();
    $batch = ProcessingBatch::query()->create([
        'sppg_unit_id' => $unit->id,
        'production_date' => today(),
        'menu_name_snapshot' => 'Nasi, Ayam, dan Sayur',
        'product_name' => 'Paket Menu Sehat',
        'target_output_quantity' => 100,
        'target_output_unit' => 'porsi',
        'petugas_id' => $user->id,
        'petugas_name_snapshot' => $user->name,
    ]);
    $batch->materialUsages()->create([
        'material_name' => 'Beras',
        'quantity' => 10,
        'unit_name' => 'kg',
        'sort_order' => 1,
    ]);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules/pengolahan/records')
        ->assertOk()
        ->assertJsonPath('data.0.id', $batch->id)
        ->assertJsonPath('data.0.title', 'Paket Menu Sehat')
        ->assertJsonPath('data.0.metrics.0.label', 'Target');

    $this->withToken($token)
        ->getJson("/api/mobile/operational-modules/pengolahan/records/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('data.number', $batch->batch_number)
        ->assertJsonPath('data.sections.0.title', 'Bahan baku digunakan')
        ->assertJsonPath('data.sections.0.items.0.title', 'Beras');
});

it('returns warehouse receipt summaries and QC detail for warehouse staff', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::StafGudang);
    $unit = SppgUnit::query()->firstOrFail();
    $receipt = StockReceipt::query()->create([
        'sppg_unit_id' => $unit->id,
        'receipt_date' => today(),
        'received_by_name' => $user->name,
        'notes' => 'Penerimaan pagi.',
    ]);
    $receipt->items()->create([
        'ingredient_name_snapshot' => 'Beras Premium',
        'unit_snapshot' => 'kg',
        'ordered_quantity' => 25,
        'received_quantity' => 25,
        'accepted_quantity' => 24,
        'rejected_quantity' => 1,
        'quality_status' => 'partial',
        'quality_notes' => 'Satu kilogram kemasan rusak.',
    ]);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.slug', 'gudang')
        ->assertJsonPath('data.1.slug', 'gudang-stok')
        ->assertJsonPath('data.2.slug', 'gudang-pengambilan')
        ->assertJsonPath('data.3.slug', 'gudang-retur');

    $this->withToken($token)
        ->getJson("/api/mobile/operational-modules/gudang/records/{$receipt->id}")
        ->assertOk()
        ->assertJsonPath('data.sections.0.items.0.title', 'Beras Premium')
        ->assertJsonFragment(['label' => 'Status mutu', 'value' => 'partial']);
});

it('blocks direct access to another divisions workspace', function (): void {
    [$user, $token] = mobileOperationalUser(UserRole::PetugasPengolahan);

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules/pemorsian/records')
        ->assertForbidden();

    $this->withToken($token)
        ->getJson('/api/mobile/operational-modules/gudang/records')
        ->assertForbidden();
});

/** @return array{User, string} */
function mobileOperationalUser(UserRole $role): array
{
    $user = User::factory()->create(['is_active' => true]);
    $user->syncRoles([$role->value]);

    return [$user, $user->createToken('Android Operational Test', ['mobile'])->plainTextToken];
}
