<?php

use App\Http\Controllers\PreparationMaterialCalculationPdfController;
use App\Http\Controllers\PreparationMaterialWastePdfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get(
        '/reports/preparation/material-handovers/{handover}/calculation-pdf',
        PreparationMaterialCalculationPdfController::class
    )->name('preparation.material-handovers.calculation-pdf');

    Route::get(
        '/reports/preparation/material-handovers/{handover}/waste-pdf',
        PreparationMaterialWastePdfController::class
    )->name('preparation.material-handovers.waste-pdf');
});
