<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'DLMS API is running.',
        'data' => [
            'phase' => 1,
        ],
    ]);
});

Route::prefix('auth')->group(function (): void {
});

Route::prefix('profile')->group(function (): void {
});

Route::prefix('applications')->group(function (): void {
});

Route::prefix('appointments')->group(function (): void {
});

Route::prefix('licenses')->group(function (): void {
});

Route::prefix('notifications')->group(function (): void {
});

Route::prefix('chatbot')->group(function (): void {
});

Route::prefix('license-types')->group(function (): void {
});

Route::prefix('service-types')->group(function (): void {
});

Route::prefix('appointment-slots')->group(function (): void {
});

Route::prefix('admin')->group(function (): void {
});
