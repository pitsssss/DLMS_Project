<?php

use App\Modules\Admin\Controllers\DocumentReviewController;
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
