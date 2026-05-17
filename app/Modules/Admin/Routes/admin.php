<?php

use App\Modules\Admin\Controllers\ApplicationLicenseController;
use App\Modules\Admin\Controllers\DocumentReviewController;
use App\Modules\Admin\Controllers\FineManagementController;
use App\Modules\Admin\Controllers\LicenseManagementController;
use App\Modules\Admin\Controllers\TestAppointmentResultController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'permission:review_documents'])
    ->group(function (): void {
        Route::get('/documents/pending-review', [DocumentReviewController::class, 'pending']);
        Route::post('/documents/{document}/approve', [DocumentReviewController::class, 'approve'])
            ->whereNumber('document')
            ->middleware('throttle:60,1');
        Route::post('/documents/{document}/reject', [DocumentReviewController::class, 'reject'])
            ->whereNumber('document')
            ->middleware('throttle:60,1');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'permission:record_test_result'])
    ->group(function (): void {
        Route::post('/test-appointments/{appointment}/record-result', [TestAppointmentResultController::class, 'store'])
            ->whereNumber('appointment')
            ->middleware('throttle:60,1');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'permission:issue_license'])
    ->group(function (): void {
        Route::post('/applications/{application}/issue-license', [ApplicationLicenseController::class, 'issue'])
            ->whereNumber('application')
            ->middleware('throttle:30,1');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'permission:manage_licenses'])
    ->group(function (): void {
        Route::post('/licenses/{license}/block', [LicenseManagementController::class, 'block'])
            ->whereNumber('license')
            ->middleware('throttle:60,1');
        Route::post('/licenses/{license}/unblock', [LicenseManagementController::class, 'unblock'])
            ->whereNumber('license')
            ->middleware('throttle:60,1');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'permission:manage_fines'])
    ->group(function (): void {
        Route::get('/fines', [FineManagementController::class, 'index']);
        Route::post('/fines', [FineManagementController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::put('/fines/{fine}', [FineManagementController::class, 'update'])
            ->whereNumber('fine')
            ->middleware('throttle:60,1');
    });
