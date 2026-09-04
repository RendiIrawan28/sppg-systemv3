<?php

use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\CleaningChecklistPeriodPdfController;
use App\Http\Controllers\CleaningWarehouseChecklistPdfController;
use App\Http\Controllers\SecurityShiftReportController;
use App\Http\Controllers\V3\EntryController;
use App\Http\Controllers\V3\LogoutController;
use App\Http\Controllers\WasteHandoverPdfController;
use App\Http\Middleware\SetV3UnitContext;
use App\Livewire\V3\Administration\TestDataCleanup;
use App\Livewire\V3\Attendance\Index as AttendanceIndex;
use App\Livewire\V3\Attendance\WorkSchedules;
use App\Livewire\V3\Auth\Login;
use App\Livewire\V3\Beneficiaries\Form as BeneficiaryForm;
use App\Livewire\V3\Beneficiaries\Import as BeneficiaryImport;
use App\Livewire\V3\Beneficiaries\Index as BeneficiaryIndex;
use App\Livewire\V3\Beneficiaries\Periods\Form as BeneficiaryPeriodForm;
use App\Livewire\V3\Beneficiaries\Periods\Index as BeneficiaryPeriodIndex;
use App\Livewire\V3\Beneficiaries\Periods\Show as BeneficiaryPeriodShow;
use App\Livewire\V3\ContainerCollections\Index as ContainerCollectionIndex;
use App\Livewire\V3\Dashboard;
use App\Livewire\V3\Field\DailyReports as FieldDailyReports;
use App\Livewire\V3\Field\Incidents\Form as FieldIncidentForm;
use App\Livewire\V3\Field\Incidents\Index as FieldIncidentIndex;
use App\Livewire\V3\Field\Plans\Form as FieldPlanForm;
use App\Livewire\V3\Field\Plans\Index as FieldPlanIndex;
use App\Livewire\V3\MasterData\Catalog as MasterDataCatalog;
use App\Livewire\V3\MasterData\Hub as MasterDataHub;
use App\Livewire\V3\MasterData\Organization as MasterDataOrganization;
use App\Livewire\V3\MasterData\Users as MasterDataUsers;
use App\Livewire\V3\Notifications\Broadcast as NotificationBroadcast;
use App\Livewire\V3\Nutrition\DailyEvaluation;
use App\Livewire\V3\Nutrition\MenuMatrix;
use App\Livewire\V3\Nutrition\Menus\Form as MenuRecipeForm;
use App\Livewire\V3\Nutrition\Menus\Nutrition as MenuNutrition;
use App\Livewire\V3\Nutrition\Requirements\Index as NutritionRequirementIndex;
use App\Livewire\V3\Nutrition\Requirements\Show as NutritionRequirementShow;
use App\Livewire\V3\Nutrition\Standards;
use App\Livewire\V3\Operations\Form as OperationalForm;
use App\Livewire\V3\Operations\Index as OperationalIndex;
use App\Livewire\V3\Portioning\Index as PortioningIndex;
use App\Livewire\V3\Preparation\Index as PreparationIndex;
use App\Livewire\V3\PreparationOutputs\Index as PreparationOutputIndex;
use App\Livewire\V3\Processing\Index as ProcessingIndex;
use App\Livewire\V3\Procurement\Index as ProcurementIndex;
use App\Livewire\V3\Procurement\Show as ProcurementShow;
use App\Livewire\V3\Security\IncidentForm as SecurityIncidentForm;
use App\Livewire\V3\Security\Index as SecurityIndex;
use App\Livewire\V3\Security\ShiftShow as SecurityShiftShow;
use App\Livewire\V3\Warehouse\Controls\Index as WarehouseControlIndex;
use App\Livewire\V3\Warehouse\NonFoodItems\Index as NonFoodItemIndex;
use App\Livewire\V3\Warehouse\OpeningStocks\Index as OpeningStockIndex;
use App\Livewire\V3\Warehouse\Receipts\CreateManual as StockReceiptCreateManual;
use App\Livewire\V3\Warehouse\Receipts\Index as StockReceiptIndex;
use App\Livewire\V3\Warehouse\Receipts\Show as StockReceiptShow;
use App\Livewire\V3\Warehouse\Stock\Index as StockIndex;
use App\Livewire\V3\Warehouse\Withdrawals\Index as WarehouseWithdrawalIndex;
use App\Livewire\V3\WasteHandovers\Form as WasteHandoverForm;
use App\Livewire\V3\WasteHandovers\Index as WasteHandoverIndex;
use App\Support\V3\MasterDataRegistry;
use App\Support\V3\OperationalModuleRegistry;
use Illuminate\Support\Facades\Route;

Route::get('/v3/login', Login::class)->name('login');

Route::post('/v3/logout', LogoutController::class)
    ->middleware('auth')
    ->name('v3.logout');

Route::middleware('auth')->prefix('v3')->name('v3.')->group(function (): void {
    Route::get('/', EntryController::class)->name('entry');

    Route::middleware(SetV3UnitContext::class)
        ->group(function (): void {
            Route::get('/dashboard', Dashboard::class)->name('dashboard');
            Route::get('/penerima-manfaat', BeneficiaryIndex::class)->name('beneficiaries.index');
            Route::get('/penerima-manfaat/tambah', BeneficiaryForm::class)->name('beneficiaries.create');
            Route::get('/penerima-manfaat/impor', BeneficiaryImport::class)->name('beneficiaries.import');
            Route::get('/penerima-manfaat/{beneficiary}/ubah', BeneficiaryForm::class)->name('beneficiaries.edit');
            Route::get('/periode-penerima', BeneficiaryPeriodIndex::class)->name('beneficiary-periods.index');
            Route::get('/periode-penerima/tambah', BeneficiaryPeriodForm::class)->name('beneficiary-periods.create');
            Route::get('/periode-penerima/{period}', BeneficiaryPeriodShow::class)->name('beneficiary-periods.show');
            Route::get('/periode-penerima/{period}/ubah', BeneficiaryPeriodForm::class)->name('beneficiary-periods.edit');
            Route::get('/gizi/evaluasi-harian', DailyEvaluation::class)->name('nutrition.daily-evaluation');
            Route::get('/gizi/standar', Standards::class)->name('nutrition.standards');
            Route::get('/gizi/perencanaan-menu', MenuMatrix::class)->name('nutrition.menu-matrix');
            Route::get('/gizi/resep/{menu}', MenuRecipeForm::class)->name('nutrition.menus.show');
            Route::get('/gizi/resep/{menu}/nilai-gizi', MenuNutrition::class)->name('nutrition.menus.nutrition');
            Route::get('/gizi/kebutuhan-bahan', NutritionRequirementIndex::class)->name('nutrition.requirements.index');
            Route::get('/gizi/kebutuhan-bahan/{plan}', NutritionRequirementShow::class)->name('nutrition.requirements.show');
            Route::get('/pengadaan', ProcurementIndex::class)->name('procurement.index');
            Route::get('/pengadaan/{procurement}', ProcurementShow::class)->name('procurement.show');
            Route::get('/gudang/penerimaan', StockReceiptIndex::class)->name('warehouse.receipts.index');
            Route::get('/gudang/penerimaan/manual', StockReceiptCreateManual::class)->name('warehouse.receipts.manual');
            Route::get('/gudang/penerimaan/{receipt}', StockReceiptShow::class)->name('warehouse.receipts.show');
            Route::get('/gudang/stok', StockIndex::class)->name('warehouse.stock.index');
            Route::get('/gudang/stok/ekspor', \App\Http\Controllers\WarehouseStockCardExportController::class)->name('warehouse.stock.export');
            Route::get('/gudang/stok-awal', OpeningStockIndex::class)->name('warehouse.opening-stocks.index');
            Route::get('/gudang/non-pangan', NonFoodItemIndex::class)->name('warehouse.non-food-items.index');
            Route::get('/gudang/pengambilan', WarehouseWithdrawalIndex::class)->name('warehouse.withdrawals.index');
            Route::get('/gudang/kontrol-stok', WarehouseControlIndex::class)->name('warehouse.controls.index');
            Route::get('/operasional/persiapan', PreparationIndex::class)->name('preparation.index');
            Route::get('/operasional/hasil-persiapan', PreparationOutputIndex::class)->name('preparation-outputs.index');
            Route::get('/operasional/pengolahan', ProcessingIndex::class)->name('processing.index');
            Route::get('/operasional/pemorsian', PortioningIndex::class)->name('portioning.index');
            Route::get('/operasional/pengambilan-ompreng', ContainerCollectionIndex::class)->name('container-collections.index');
            Route::get('/operasional/berita-acara-limbah', WasteHandoverIndex::class)->name('waste-handovers.index');
            Route::get('/operasional/berita-acara-limbah/tambah', WasteHandoverForm::class)->name('waste-handovers.create');
            Route::get('/operasional/berita-acara-limbah/{report}', WasteHandoverForm::class)->whereNumber('report')->name('waste-handovers.show');
            Route::get('/operasional/berita-acara-limbah/{wasteHandoverReport}/pdf', WasteHandoverPdfController::class)->name('waste-handovers.pdf');
            Route::get('/operasional/kebersihan/checklist/{cleaningArea}/pdf', CleaningChecklistPeriodPdfController::class)->name('cleaning.checklist-period.pdf');
            Route::get('/operasional/kebersihan/checklist-gudang/pdf', CleaningWarehouseChecklistPdfController::class)->name('cleaning.warehouse-checklists.pdf');
            Route::get('/lapangan/rencana', FieldPlanIndex::class)->name('field.plans.index');
            Route::get('/lapangan/rencana/tambah', FieldPlanForm::class)->name('field.plans.create');
            Route::get('/lapangan/rencana/{plan}', FieldPlanForm::class)->name('field.plans.show');
            Route::get('/lapangan/laporan-harian', FieldDailyReports::class)->name('field.daily-reports');
            Route::get('/lapangan/insiden', FieldIncidentIndex::class)->name('field.incidents.index');
            Route::get('/lapangan/insiden/tambah', FieldIncidentForm::class)->name('field.incidents.create');
            Route::get('/lapangan/insiden/{incident}', FieldIncidentForm::class)->name('field.incidents.show');
            Route::get('/keamanan', SecurityIndex::class)->name('security.index');
            Route::get('/keamanan/shift/{shift}/pdf', [SecurityShiftReportController::class, 'pdf'])->name('security.shifts.pdf');
            Route::get('/keamanan/shift/{shift}/excel', [SecurityShiftReportController::class, 'xlsx'])->name('security.shifts.xlsx');
            Route::get('/keamanan/shift/{shift}', SecurityShiftShow::class)->name('security.shifts.show');
            Route::get('/keamanan/insiden/tambah', SecurityIncidentForm::class)->name('security.incidents.create');
            Route::get('/keamanan/insiden/{incident}', SecurityIncidentForm::class)->name('security.incidents.show');
            Route::get('/notifikasi/kirim', NotificationBroadcast::class)->name('notifications.broadcast');
            Route::get('/administrasi/pembersihan-data-uji', TestDataCleanup::class)->name('administration.test-data-cleanup');
            Route::get('/presensi-relawan', AttendanceIndex::class)->name('attendance.index');
            Route::get('/presensi-relawan/jam-kerja', WorkSchedules::class)->name('attendance.work-schedules');
            Route::get('/presensi-relawan/pdf', [AttendanceReportController::class, 'pdf'])->name('attendance.pdf');
            Route::get('/presensi-relawan/excel', [AttendanceReportController::class, 'xlsx'])->name('attendance.xlsx');
            Route::get('/operasional/{module}', OperationalIndex::class)
                ->whereIn('module', app(OperationalModuleRegistry::class)->genericWebSlugs())
                ->name('operations.index');
            Route::get('/operasional/{module}/tambah', OperationalForm::class)
                ->whereIn('module', app(OperationalModuleRegistry::class)->genericWebSlugs())
                ->name('operations.create');
            Route::get('/operasional/{module}/{record}', OperationalForm::class)
                ->whereIn('module', app(OperationalModuleRegistry::class)->genericWebSlugs())
                ->whereNumber('record')
                ->name('operations.show');
            Route::get('/master-data', MasterDataHub::class)->name('master-data.index');
            Route::get('/master-data/organisasi', MasterDataOrganization::class)->name('master-data.organization');
            Route::get('/master-data/pengguna', MasterDataUsers::class)->name('master-data.users');
            Route::get('/master-data/{catalog}', MasterDataCatalog::class)
                ->whereIn('catalog', app(MasterDataRegistry::class)->slugs())
                ->name('master-data.catalog');
        });
});
