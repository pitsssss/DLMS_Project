<?php

use App\Modules\Applications\Controllers\ApplicationController;
use App\Modules\Applications\Controllers\LicenseTypeController;
use App\Modules\Applications\Controllers\ServiceTypeController;
use App\Modules\Auth\Controllers\ForgotPasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Modules\Auth\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'DLMS API is running.',
        'data' => [
            'phase' => 3,
        ],
    ]);
});

Route::post('/auth/register', [RegisterController::class, 'register']);
Route::post('/auth/verify-otp', [RegisterController::class, 'verifyOtp']);
Route::post('/auth/login', [LoginController::class, 'login']);

Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('/auth/forgot-password', [ForgotPasswordController::class, 'forgot']);
    Route::post('/auth/verify-forgot-password-otp', [ForgotPasswordController::class, 'verifyForgotPasswordOtp']);
    Route::post('/auth/reset-password', [ForgotPasswordController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [LogoutController::class, 'logout']);
    Route::get('/auth/me', [ProfileController::class, 'me']);

    Route::put('/profile/complete', [ProfileController::class, 'complete']);
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::put('/profile/change-password', [ProfileController::class, 'changePassword']);
});

Route::get('/license-types', [LicenseTypeController::class, 'index']);
Route::get('/service-types', [ServiceTypeController::class, 'index']);

Route::middleware(['auth:sanctum', 'citizen'])->prefix('applications')->group(function (): void {
    Route::get('/', [ApplicationController::class, 'index']);
    Route::post('/', [ApplicationController::class, 'store']);
    Route::get('/{application}', [ApplicationController::class, 'show'])->whereNumber('application');
});

Route::prefix('appointments')->group(function (): void {
});

Route::prefix('licenses')->group(function (): void {
});

Route::prefix('notifications')->group(function (): void {
});

Route::prefix('chatbot')->group(function (): void {
});

Route::prefix('appointment-slots')->group(function (): void {
});

Route::prefix('admin')->group(function (): void {
});
