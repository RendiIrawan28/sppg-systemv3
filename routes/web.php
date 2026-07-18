<?php

use App\Http\Controllers\BeneficiaryPeriodExportController;
use App\Http\Controllers\BeneficiaryTemplateController;
use App\Http\Controllers\CleaningSessionPdfController;
use App\Http\Controllers\DistributionRunPdfController;
use App\Http\Controllers\FieldDailyReportExcelController;
use App\Http\Controllers\FieldDailyReportPdfController;
use App\Http\Controllers\FieldDistributionPlanExcelController;
use App\Http\Controllers\FieldDistributionPlanPdfController;
use App\Http\Controllers\NutritionExportController;
use App\Http\Controllers\PortioningSessionPdfController;
use App\Http\Controllers\ProcessingBatchPdfController;
use App\Http\Controllers\WashingSessionPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')
    ->get('/beneficiary-template/{type}', BeneficiaryTemplateController::class)
    ->whereIn('type', ['school', 'posyandu'])
    ->name('beneficiaries.template');

Route::middleware('auth')->group(function (): void {
    Route::get('/processing-batches/{processingBatch}/pdf', ProcessingBatchPdfController::class)
        ->name('processing-batches.pdf');
    Route::get('/portioning-sessions/{portioningSession}/pdf', PortioningSessionPdfController::class)
        ->name('portioning-sessions.pdf');
    Route::get('/distribution-runs/{distributionRun}/pdf', DistributionRunPdfController::class)
        ->name('distribution-runs.pdf');
    Route::get('/washing-sessions/{washingSession}/pdf', WashingSessionPdfController::class)
        ->name('washing-sessions.pdf');
    Route::get('/cleaning-sessions/{cleaningSession}/pdf', CleaningSessionPdfController::class)
        ->name('cleaning-sessions.pdf');
    Route::get('/field-assistant/distribution-plans/{fieldDistributionPlan}/pdf', FieldDistributionPlanPdfController::class)
        ->name('field-distribution-plans.pdf');
    Route::get('/field-assistant/distribution-plans/{fieldDistributionPlan}/excel', FieldDistributionPlanExcelController::class)
        ->name('field-distribution-plans.excel');
    Route::get('/field-assistant/daily-reports/{fieldDailyReport}/pdf', FieldDailyReportPdfController::class)
        ->name('field-daily-reports.pdf');
    Route::get('/field-assistant/daily-reports/{fieldDailyReport}/excel', FieldDailyReportExcelController::class)
        ->name('field-daily-reports.excel');
    Route::get('/beneficiary-periods/{beneficiaryPeriod}/pdf', [BeneficiaryPeriodExportController::class, 'pdf'])
        ->name('beneficiary-periods.pdf');
    Route::get('/beneficiary-periods/{beneficiaryPeriod}/excel', [BeneficiaryPeriodExportController::class, 'excel'])
        ->name('beneficiary-periods.excel');
});

Route::middleware(['auth'])
    ->prefix('nutrition/export')
    ->group(function (): void {
        Route::get('menu-cycles/{cycle}/pdf', [NutritionExportController::class, 'menuCyclePdf'])
            ->name('nutrition.menu-cycles.pdf');

        Route::get('requirements/{plan}/pdf', [NutritionExportController::class, 'requirementPdf'])
            ->name('nutrition.requirements.pdf');
        Route::get('requirements/{plan}/excel', [NutritionExportController::class, 'requirementExcel'])
            ->name('nutrition.requirements.excel');

        Route::get('evaluations/{evaluation}/pdf', [NutritionExportController::class, 'evaluationPdf'])
            ->name('nutrition.evaluations.pdf');

        Route::get('daily-reports/{report}/pdf', [NutritionExportController::class, 'dailyReportPdf'])
            ->name('nutrition.daily-reports.pdf');
        Route::get('daily-reports/{report}/excel', [NutritionExportController::class, 'dailyReportExcel'])
            ->name('nutrition.daily-reports.excel');
    });

require base_path('routes/web_preparation_waste_handover.php');

require base_path('routes/web_preparation_material_handover_reports.php');

require base_path('routes/v3.php');
