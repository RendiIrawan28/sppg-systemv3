<?php

use App\Http\Controllers\Api\AttendanceDeviceController;
use App\Http\Controllers\Api\FieldPlanController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileDeviceTokenController;
use App\Http\Controllers\Api\MobileDocumentController;
use App\Http\Controllers\Api\MobileNotificationController;
use App\Http\Controllers\Api\MobileOperationalController;
use App\Http\Controllers\Api\MobileSecurityController;
use App\Http\Controllers\Api\MobileTaskController;
use App\Http\Middleware\EnsureMobileAccessToken;
use Illuminate\Support\Facades\Route;

Route::prefix('iot/attendance')->middleware('throttle:120,1')->group(function (): void {
    Route::get('/configuration', [AttendanceDeviceController::class, 'configuration']);
    Route::post('/tap', [AttendanceDeviceController::class, 'tap']);
});

Route::prefix('mobile')->group(function (): void {
    Route::post('/login', [MobileAuthController::class, 'login']);
    Route::get('/security/reports/{report}/photo', [MobileSecurityController::class, 'photo'])
        ->middleware('signed:relative')
        ->name('api.mobile.security.reports.photo');

    Route::middleware(['auth:sanctum', EnsureMobileAccessToken::class])->group(function (): void {
        Route::get('/user', [MobileAuthController::class, 'user']);
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::post('/device-tokens', [MobileDeviceTokenController::class, 'store']);
        Route::delete('/device-tokens/{installationId}', [MobileDeviceTokenController::class, 'destroy']);
        Route::get('/tasks', [MobileTaskController::class, 'index']);
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::get('/notifications/status', [MobileNotificationController::class, 'status']);
        Route::post('/notifications/test', [MobileNotificationController::class, 'test']);
        Route::post('/notifications/read-all', [MobileNotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [MobileNotificationController::class, 'read']);
        Route::get('/security/overview', [MobileSecurityController::class, 'overview']);
        Route::post('/security/shifts', [MobileSecurityController::class, 'start']);
        Route::post('/security/shifts/{shift}/reports', [MobileSecurityController::class, 'report']);
        Route::get('/field-plans', [FieldPlanController::class, 'index']);
        Route::get('/field-plans/options', [FieldPlanController::class, 'options']);
        Route::post('/field-plans', [FieldPlanController::class, 'store']);
        Route::get('/field-plans/{plan}', [FieldPlanController::class, 'show']);
        Route::put('/field-plans/{plan}', [FieldPlanController::class, 'update']);
        Route::delete('/field-plans/{plan}', [FieldPlanController::class, 'destroy']);
        Route::post('/field-plans/{plan}/refresh-beneficiaries', [FieldPlanController::class, 'refreshBeneficiaries']);
        Route::get('/field-plans/{plan}/readiness', [FieldPlanController::class, 'readiness']);
        Route::post('/field-plans/{plan}/activate', [FieldPlanController::class, 'activate']);
        Route::get('/field-plans/{fieldDistributionPlan}/document', [MobileDocumentController::class, 'fieldPlan']);
        Route::get('/operational-modules', [MobileOperationalController::class, 'modules']);
        Route::get('/operational-modules/{module}/records', [MobileOperationalController::class, 'index']);
        Route::post('/operational-modules/{module}/records', [MobileOperationalController::class, 'store']);
        Route::get('/operational-modules/{module}/records/{record}', [MobileOperationalController::class, 'show'])
            ->whereNumber('record');
        Route::get('/operational-modules/{module}/records/{record}/document', [MobileDocumentController::class, 'operational'])
            ->whereNumber('record');
        Route::put('/operational-modules/{module}/records/{record}', [MobileOperationalController::class, 'update'])
            ->whereNumber('record');
        Route::delete('/operational-modules/{module}/records/{record}', [MobileOperationalController::class, 'destroy'])
            ->whereNumber('record');
        Route::post('/operational-modules/{module}/records/{record}/actions/{action}', [MobileOperationalController::class, 'action'])
            ->whereNumber('record');
        Route::post('/operational-modules/{module}/records/{record}/relations/{relation}', [MobileOperationalController::class, 'storeRelation'])
            ->whereNumber('record');
        Route::put('/operational-modules/{module}/records/{record}/relations/{relation}/{item}', [MobileOperationalController::class, 'updateRelation'])
            ->whereNumber(['record', 'item']);
        Route::delete('/operational-modules/{module}/records/{record}/relations/{relation}/{item}', [MobileOperationalController::class, 'destroyRelation'])
            ->whereNumber(['record', 'item']);
        Route::post('/operational-modules/{module}/records/{record}/relations/{relation}/{item}/actions/{action}', [MobileOperationalController::class, 'relationAction'])
            ->whereNumber(['record', 'item']);
    });
});
