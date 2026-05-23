<?php

use App\Http\Controllers\Dev\DlmsTestingDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['dev.dashboard'])
    ->prefix('dev-dashboard')
    ->name('dev-dashboard.')
    ->group(function (): void {
        Route::get('/', [DlmsTestingDashboardController::class, 'index'])->name('index');
        Route::post('/action', [DlmsTestingDashboardController::class, 'runAction'])->name('action');
        Route::post('/reset-session', [DlmsTestingDashboardController::class, 'resetSession'])->name('reset');
    });
