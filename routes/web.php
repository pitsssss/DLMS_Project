<?php

use App\Http\Controllers\Dev\DlmsTestingDashboardController;
use App\Modules\Payments\Controllers\PaymentReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Public Stripe Checkout return pages (display-only; no auth)
|--------------------------------------------------------------------------
*/
Route::get('/payment/success', [PaymentReturnController::class, 'success'])
    ->name('payment.return.success');
Route::get('/payment/processing', [PaymentReturnController::class, 'processing'])
    ->name('payment.return.processing');
Route::get('/payment/cancel', [PaymentReturnController::class, 'cancel'])
    ->name('payment.return.cancel');

Route::middleware(['dev.dashboard'])
    ->prefix('dev-dashboard')
    ->name('dev-dashboard.')
    ->group(function (): void {
        Route::get('/', [DlmsTestingDashboardController::class, 'index'])->name('index');
        Route::post('/action', [DlmsTestingDashboardController::class, 'runAction'])->name('action');
        Route::post('/reset-session', [DlmsTestingDashboardController::class, 'resetSession'])->name('reset');
    });
