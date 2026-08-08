<?php

use App\Modules\Settings\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')
    ->middleware(['auth:sanctum', 'citizen', 'locale'])
    ->group(function () {
        Route::get('/', [SettingsController::class, 'index']);
        Route::put('/preferences', [SettingsController::class, 'updatePreferences']);
        Route::put('/change-password', [SettingsController::class, 'changePassword']);
    });
