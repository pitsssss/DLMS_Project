<?php

use App\Modules\Content\Controllers\ContactMessageController;
use App\Modules\Content\Controllers\ContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('content')->group(function () {
    Route::get('/faqs', [ContentController::class, 'faqs']);
    Route::get('/privacy-policy', [ContentController::class, 'privacyPolicy']);
    Route::get('/contact-info', [ContentController::class, 'contactInfo']);
});

Route::post('/contact-messages', [ContactMessageController::class, 'store'])
    ->middleware('throttle:20,1');
