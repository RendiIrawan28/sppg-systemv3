<?php

use App\Support\V3\MasterDataRegistry;
use App\Support\V3\OperationalModuleRegistry;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();
});

it('menampilkan halaman masuk tanpa catatan migrasi', function (): void {
    $this->get('/v3/login')
        ->assertOk()
        ->assertSee('Masuk ke ruang kerja')
        ->assertSee('Sistem Operasional Terpadu')
        ->assertDontSee('Native Laravel + Livewire')
        ->assertDontSee('SPPG V3')
        ->assertSeeHtml('data-theme-toggle')
        ->assertSee('sppg-theme');
});

it('melindungi ruang kerja V3 dari pengguna yang belum masuk', function (): void {
    $this->get('/v3')
        ->assertRedirect('/v3/login');
});

it('memiliki route utama untuk rollout V3', function (): void {
    expect(route('v3.entry'))->toEndWith('/v3')
        ->and(route('login'))->toEndWith('/v3/login')
        ->and(route('v3.dashboard'))->toEndWith('/v3/dashboard')
        ->and(route('v3.beneficiaries.index'))->toEndWith('/v3/penerima-manfaat')
        ->and(route('v3.beneficiaries.create'))->toEndWith('/v3/penerima-manfaat/tambah')
        ->and(route('v3.beneficiaries.import'))->toEndWith('/v3/penerima-manfaat/impor')
        ->and(route('v3.beneficiary-periods.index'))->toEndWith('/v3/periode-penerima')
        ->and(route('v3.beneficiary-periods.create'))->toEndWith('/v3/periode-penerima/tambah')
        ->and(route('v3.nutrition.menu-matrix'))->toEndWith('/v3/gizi/perencanaan-menu')
        ->and(route('v3.nutrition.requirements.index'))->toEndWith('/v3/gizi/kebutuhan-bahan')
        ->and(route('v3.nutrition.daily-evaluation'))->toEndWith('/v3/gizi/evaluasi-harian')
        ->and(route('v3.nutrition.standards'))->toEndWith('/v3/gizi/standar')
        ->and(route('v3.procurement.index'))->toEndWith('/v3/pengadaan')
        ->and(route('v3.warehouse.receipts.index'))->toEndWith('/v3/gudang/penerimaan')
        ->and(route('v3.warehouse.stock.index'))->toEndWith('/v3/gudang/stok')
        ->and(route('v3.warehouse.withdrawals.index'))->toEndWith('/v3/gudang/pengambilan')
        ->and(route('v3.warehouse.controls.index'))->toEndWith('/v3/gudang/kontrol-stok')
        ->and(route('v3.preparation.index'))->toEndWith('/v3/operasional/persiapan')
        ->and(route('v3.field.plans.index'))->toEndWith('/v3/lapangan/rencana')
        ->and(route('v3.field.daily-reports'))->toEndWith('/v3/lapangan/laporan-harian')
        ->and(route('v3.field.incidents.index'))->toEndWith('/v3/lapangan/insiden')
        ->and(route('v3.processing.index'))->toEndWith('/v3/operasional/pengolahan')
        ->and(route('v3.portioning.index'))->toEndWith('/v3/operasional/pemorsian')
        ->and(route('v3.operations.index', 'distribusi'))->toEndWith('/v3/operasional/distribusi')
        ->and(route('v3.operations.index', 'pencucian'))->toEndWith('/v3/operasional/pencucian')
        ->and(route('v3.operations.index', 'kebersihan'))->toEndWith('/v3/operasional/kebersihan')
        ->and(route('v3.master-data.index'))->toEndWith('/v3/master-data')
        ->and(route('v3.master-data.organization'))->toEndWith('/v3/master-data/organisasi')
        ->and(route('v3.master-data.users'))->toEndWith('/v3/master-data/pengguna')
        ->and(route('v3.master-data.catalog', 'ingredients'))->toEndWith('/v3/master-data/ingredients');
});

it('melindungi seluruh fitur penerima V3 dari pengguna yang belum masuk', function (string $url): void {
    $this->get($url)->assertRedirect('/v3/login');
})->with([
    '/v3/penerima-manfaat',
    '/v3/penerima-manfaat/tambah',
    '/v3/penerima-manfaat/impor',
    '/v3/periode-penerima',
    '/v3/periode-penerima/tambah',
    '/v3/gizi/perencanaan-menu',
    '/v3/gizi/kebutuhan-bahan',
    '/v3/pengadaan',
    '/v3/gudang/penerimaan',
    '/v3/gudang/stok',
    '/v3/gudang/pengambilan',
    '/v3/gudang/kontrol-stok',
    '/v3/operasional/persiapan',
    '/v3/lapangan/rencana',
    '/v3/lapangan/laporan-harian',
    '/v3/lapangan/insiden',
    '/v3/operasional/pengolahan',
    '/v3/operasional/pemorsian',
    '/v3/operasional/distribusi',
    '/v3/operasional/pencucian',
    '/v3/operasional/kebersihan',
    '/v3/master-data',
    '/v3/master-data/organisasi',
    '/v3/master-data/pengguna',
    '/v3/master-data/ingredients',
]);

it('mendaftarkan seluruh katalog master data native V3', function (): void {
    $registry = app(MasterDataRegistry::class);

    expect($registry->slugs())->toHaveCount(13)
        ->toContain(
            'schools', 'posyandus', 'beneficiary-categories', 'measurement-units',
            'nutrition-components', 'allergens', 'ingredients', 'nutrition-standards',
            'portion-standards', 'suppliers', 'service-holidays', 'cleaning-areas', 'divisions',
        );
});

it('tidak lagi memakai slug unit pada route V3', function (): void {
    expect(route('v3.dashboard'))->not->toContain('/unit/');
});

it('mendaftarkan seluruh modul operasional native V3', function (): void {
    expect(app(OperationalModuleRegistry::class)->slugs())
        ->toBe(['pengolahan', 'pemorsian', 'distribusi', 'pencucian', 'kebersihan']);
});

it('tidak membuka kembali formulir generik lama untuk Pengolahan dan Pemorsian', function (string $url): void {
    $this->get($url)->assertNotFound();
})->with([
    '/v3/operasional/pengolahan/tambah',
    '/v3/operasional/pengolahan/1',
    '/v3/operasional/pemorsian/tambah',
    '/v3/operasional/pemorsian/1',
]);

it('menonaktifkan panel V2 dan permission tenant', function (): void {
    expect(config('permission.teams'))->toBeFalse()
        ->and(Route::has('filament.admin.auth.login'))->toBeFalse();

    $this->get('/admin')->assertNotFound();
});
