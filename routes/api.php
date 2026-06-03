<?php

use App\Modules\Applications\Controllers\ApplicationController;
use App\Modules\Applications\Controllers\ApplicationDocumentController;
use App\Modules\Applications\Controllers\LicenseTypeController;
use App\Modules\Applications\Controllers\ServiceTypeController;
use App\Modules\Auth\Controllers\ForgotPasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Appointments\Controllers\ApplicationAppointmentController;
use App\Modules\Appointments\Controllers\AppointmentController;
use App\Modules\Appointments\Controllers\AppointmentSlotController;
use App\Modules\Payments\Controllers\ApplicationPaymentController;
use App\Modules\Payments\Controllers\StripeWebhookController;
use App\Modules\Tests\Controllers\ApplicationTestResultController;
use App\Modules\Fines\Controllers\FineController;
use App\Modules\Licenses\Controllers\LicenseController;
use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => __('messages.ping.running'),
        'data' => [
            'phase' => 9,
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

Route::middleware(['auth:sanctum', 'citizen'])->group(function (): void {
    Route::get('/profile/status', [ProfileController::class, 'status']);
});

Route::middleware(['auth:sanctum', 'citizen'])->prefix('applications')->group(function (): void {
    Route::get('/', [ApplicationController::class, 'index']);
    Route::get('/{application}/required-documents', [ApplicationDocumentController::class, 'requiredDocuments'])
        ->whereNumber('application');
    Route::get('/{application}/documents', [ApplicationDocumentController::class, 'index'])
        ->whereNumber('application');
    Route::get('/{application}/fee', [ApplicationPaymentController::class, 'showFee'])
        ->whereNumber('application');
    Route::get('/{application}/payments', [ApplicationPaymentController::class, 'index'])
        ->whereNumber('application');
    Route::get('/{application}/payments/{payment}/status', [ApplicationPaymentController::class, 'status'])
        ->whereNumber('application')
        ->whereNumber('payment');
    Route::get('/{application}/available-tests', [ApplicationAppointmentController::class, 'availableTests'])
        ->whereNumber('application');
    Route::get('/{application}/appointments', [ApplicationAppointmentController::class, 'index'])
        ->whereNumber('application');
    Route::get('/{application}/test-results', [ApplicationTestResultController::class, 'index'])
        ->whereNumber('application');
    Route::get('/{application}', [ApplicationController::class, 'show'])->whereNumber('application');

    Route::middleware('profile.approved')->group(function (): void {
        Route::post('/', [ApplicationController::class, 'store']);
        Route::post('/{application}/payments', [ApplicationPaymentController::class, 'store'])
            ->whereNumber('application')
            ->middleware('throttle:15,1');
        Route::post('/{application}/payments/{payment}/confirm', [ApplicationPaymentController::class, 'confirm'])
            ->whereNumber('application')
            ->whereNumber('payment')
            ->middleware('throttle:15,1');
        Route::post('/{application}/appointments', [ApplicationAppointmentController::class, 'store'])
            ->whereNumber('application')
            ->middleware('throttle:15,1');
        Route::post('/{application}/documents', [ApplicationDocumentController::class, 'store'])
            ->whereNumber('application')
            ->middleware('throttle:30,1');
        Route::post('/{application}/submit-documents', [ApplicationDocumentController::class, 'submit'])
            ->whereNumber('application')
            ->middleware('throttle:10,1');
    });
});

Route::middleware(['auth:sanctum', 'citizen'])->group(function (): void {
    Route::get('/appointment-slots', [AppointmentSlotController::class, 'index']);

    Route::middleware('profile.approved')->group(function (): void {
        Route::put('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
            ->whereNumber('appointment')
            ->middleware('throttle:15,1');
        Route::delete('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->whereNumber('appointment')
            ->middleware('throttle:15,1');

        Route::post('/licenses/{license}/renew', [LicenseController::class, 'renew'])
            ->whereNumber('license')
            ->middleware('throttle:10,1');
        Route::post('/licenses/{license}/replacement', [LicenseController::class, 'replacement'])
            ->whereNumber('license')
            ->middleware('throttle:10,1');
        Route::post('/licenses/{license}/unblock-request', [LicenseController::class, 'unblockRequest'])
            ->whereNumber('license')
            ->middleware('throttle:10,1');
    });

    Route::get('/licenses', [LicenseController::class, 'index']);
    Route::get('/licenses/{license}', [LicenseController::class, 'show'])->whereNumber('license');

    Route::get('/fines', [FineController::class, 'index']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification');

    Route::prefix('ai-agent')->group(function (): void {
        require base_path('app/Modules/AIAgent/Routes/ai-agent.php');
    });
});

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:100,1');

require base_path('app/Modules/Dashboard/Routes/dashboard.php');

require base_path('app/Modules/Admin/Routes/admin.php');

Route::prefix('chatbot')->group(function (): void {
});
