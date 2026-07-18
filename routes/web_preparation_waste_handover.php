<?php

use App\Http\Controllers\PreparationWasteHandoverPdfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->get(
        '/reports/preparation/waste-handovers/{report}/pdf',
        PreparationWasteHandoverPdfController::class
    )
    ->name('preparation.waste-handovers.pdf');
