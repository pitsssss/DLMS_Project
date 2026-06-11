<?php

use App\Modules\Content\Controllers\DashboardContactMessageController;
use App\Modules\Dashboard\Controllers\DashboardAuthController;
use App\Modules\Dashboard\Controllers\DashboardEmployeeController;
use App\Modules\Dashboard\Controllers\DashboardRoleController;
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
            Route::patch('/employees/{user}/toggle-active', [DashboardEmployeeController::class, 'toggleActive'])->whereNumber('user');
            Route::post('/employees/{user}/reset-password', [DashboardEmployeeController::class, 'resetPassword'])->whereNumber('user');
            Route::post('/employees/{user}/assign-role', [DashboardEmployeeController::class, 'assignRole'])->whereNumber('user');
        });
    });
