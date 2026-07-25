<?php

use App\Http\Controllers\Api\FieldPlanController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileOperationalController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function (): void {
    Route::post('/login', [MobileAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', [MobileAuthController::class, 'user']);
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::get('/field-plans', [FieldPlanController::class, 'index']);
        Route::get('/field-plans/{plan}', [FieldPlanController::class, 'show']);
        Route::put('/field-plans/{plan}', [FieldPlanController::class, 'update']);
        Route::get('/field-plans/{plan}/readiness', [FieldPlanController::class, 'readiness']);
        Route::post('/field-plans/{plan}/activate', [FieldPlanController::class, 'activate']);
        Route::get('/operational-modules', [MobileOperationalController::class, 'modules']);
        Route::get('/operational-modules/{module}/records', [MobileOperationalController::class, 'index']);
        Route::get('/operational-modules/{module}/records/{record}', [MobileOperationalController::class, 'show'])
            ->whereNumber('record');
    });
});
