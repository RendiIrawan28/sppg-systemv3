<?php

use App\Http\Controllers\PreparationSessionCalculationPdfController;
use App\Http\Controllers\PreparationSessionWastePdfController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get(
        '/reports/preparation/sessions/{session}/calculation-pdf',
        PreparationSessionCalculationPdfController::class,
    )->name('preparation.sessions.calculation-pdf');

    Route::get(
        '/reports/preparation/sessions/{session}/waste-pdf',
        PreparationSessionWastePdfController::class,
    )->name('preparation.sessions.waste-pdf');
});
