<?php

use App\Modules\Content\Controllers\DashboardContactMessageController;
use App\Modules\Dashboard\Controllers\DashboardApplicationController;
use App\Modules\Dashboard\Controllers\DashboardAuthController;
use App\Modules\Dashboard\Controllers\DashboardEmployeeController;
use App\Modules\Dashboard\Controllers\DashboardLicenseTypeController;
use App\Modules\Dashboard\Controllers\DashboardRoleController;
use App\Modules\Dashboard\Controllers\DashboardServiceTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard/auth')->group(function (): void {
    Route::post('/login', [DashboardAuthController::class, 'login']);

    Route::middleware('throttle:5,1')->group(function (): void {
        Route::post('/forgot-password', [DashboardAuthController::class, 'forgotPassword']);
        Route::post('/verify-forgot-password-otp', [DashboardAuthController::class, 'verifyForgotPasswordOtp']);
        Route::post('/reset-password', [DashboardAuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'dashboard'])->group(function (): void {
        Route::post('/logout', [DashboardAuthController::class, 'logout']);
        Route::get('/me', [DashboardAuthController::class, 'me']);
        Route::put('/change-password', [DashboardAuthController::class, 'changePassword']);
    });
});

Route::prefix('dashboard')
    ->middleware(['auth:sanctum', 'dashboard'])
    ->group(function (): void {
        Route::middleware('permission:view_roles')->group(function (): void {
            Route::get('/roles', [DashboardRoleController::class, 'index']);
            Route::get('/roles/{role}', [DashboardRoleController::class, 'show']);
            Route::get('/permissions', [DashboardRoleController::class, 'permissions']);
        });

        Route::middleware('permission:view_contact_messages')->group(function (): void {
            Route::get('/contact-messages', [DashboardContactMessageController::class, 'index']);
        });

        Route::middleware('permission:manage_contact_messages')->group(function (): void {
            Route::patch('/contact-messages/{contactMessage}/status', [DashboardContactMessageController::class, 'updateStatus'])
                ->whereNumber('contactMessage');
        });

        Route::middleware('permission:manage_employees')->group(function (): void {
            Route::get('/employees', [DashboardEmployeeController::class, 'index']);
            Route::post('/employees', [DashboardEmployeeController::class, 'store']);
            Route::get('/employees/{user}', [DashboardEmployeeController::class, 'show'])->whereNumber('user');
            Route::put('/employees/{user}', [DashboardEmployeeController::class, 'update'])->whereNumber('user');
            Route::patch('/employees/{user}/activate', [DashboardEmployeeController::class, 'activate'])->whereNumber('user');
            Route::patch('/employees/{user}/deactivate', [DashboardEmployeeController::class, 'deactivate'])->whereNumber('user');
            Route::patch('/employees/{user}/toggle-active', [DashboardEmployeeController::class, 'toggleActive'])->whereNumber('user');
            Route::post('/employees/{user}/reset-password', [DashboardEmployeeController::class, 'resetPassword'])->whereNumber('user');
            Route::post('/employees/{user}/assign-role', [DashboardEmployeeController::class, 'assignRole'])->whereNumber('user');
        });

        Route::middleware('permission:view_applications')->group(function (): void {
            Route::get('/applications', [DashboardApplicationController::class, 'index']);
            // Lookup application details by application number (not internal id). The table shows application_number.
            Route::get('/applications/{application_number}', [DashboardApplicationController::class, 'show'])
                ->where('application_number', '[A-Za-z0-9_\-]+');
        });

        Route::middleware('permission:manage_settings')->group(function (): void {
            Route::get('/license-types', [DashboardLicenseTypeController::class, 'index']);
            Route::post('/license-types', [DashboardLicenseTypeController::class, 'store']);
            Route::get('/license-types/{licenseType}', [DashboardLicenseTypeController::class, 'show'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}', [DashboardLicenseTypeController::class, 'update'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}/activate', [DashboardLicenseTypeController::class, 'activate'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}/deactivate', [DashboardLicenseTypeController::class, 'deactivate'])->whereNumber('licenseType');

            Route::get('/service-types', [DashboardServiceTypeController::class, 'index']);
            Route::post('/service-types', [DashboardServiceTypeController::class, 'store']);
            Route::get('/service-types/{serviceType}', [DashboardServiceTypeController::class, 'show'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}', [DashboardServiceTypeController::class, 'update'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}/activate', [DashboardServiceTypeController::class, 'activate'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}/deactivate', [DashboardServiceTypeController::class, 'deactivate'])->whereNumber('serviceType');
        });
    });
