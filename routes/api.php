<?php

use App\Modules\Applications\Controllers\ApplicationController;
use App\Modules\Applications\Controllers\ApplicationDocumentController;
use App\Modules\Applications\Controllers\LicenseTypeController;
use App\Modules\Applications\Controllers\ServiceTypeController;
use App\Modules\Applications\Controllers\TestTypeController;
use App\Modules\Auth\Controllers\ForgotPasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Appointments\Controllers\ApplicationAppointmentController;
use App\Modules\Appointments\Controllers\AppointmentController;
use App\Modules\Appointments\Controllers\AppointmentSlotController;
use App\Modules\Payments\Controllers\ApplicationPaymentController;
use App\Modules\Payments\Controllers\CitizenPaymentController;
use App\Modules\Payments\Controllers\FinePaymentController;
use App\Modules\Payments\Controllers\StripeWebhookController;
use App\Modules\Tests\Controllers\ApplicationTestResultController;
use App\Modules\Fines\Controllers\FineController;
use App\Modules\Licenses\Controllers\LicenseController;
use App\Modules\Licenses\Controllers\LicenseVerificationController;
use App\Modules\Devices\Controllers\PushDeviceController;
use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public citizen-facing routes (locale after Accept-Language only)
|--------------------------------------------------------------------------
*/
Route::middleware('locale')->group(function (): void {
    Route::get('/ping', function () {
        return response()->json([
            'success' => true,
            'message' => __('messages.ping.running'),
            'data' => [
                'phase' => 9,
            ],
        ]);
    });

    Route::post('/auth/register', [RegisterController::class, 'register'])
        ->middleware('throttle:citizen-register');
    Route::post('/auth/verify-otp', [RegisterController::class, 'verifyOtp'])
        ->middleware('throttle:registration-otp-verify');
    Route::post('/auth/login', [LoginController::class, 'login'])
        ->middleware('throttle:citizen-login');

    Route::middleware('throttle:5,1')->group(function (): void {
        Route::post('/auth/forgot-password', [ForgotPasswordController::class, 'forgot']);
        Route::post('/auth/verify-forgot-password-otp', [ForgotPasswordController::class, 'verifyForgotPasswordOtp']);
        Route::post('/auth/reset-password', [ForgotPasswordController::class, 'resetPassword']);
    });

    Route::get('/license-types', [LicenseTypeController::class, 'index']);
    Route::get('/service-types', [ServiceTypeController::class, 'index']);
    Route::get('/test-types', [TestTypeController::class, 'index']);

    Route::get('/licenses/verify/{verificationToken}', [LicenseVerificationController::class, 'show'])
        ->middleware('throttle:30,1')
        ->where('verificationToken', '[A-Za-z0-9]+');
});

/*
|--------------------------------------------------------------------------
| Authenticated citizen routes (auth → locale → citizen)
| Locale runs before citizen so middleware access messages honor Accept-Language.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'locale'])->group(function (): void {
    Route::post('/auth/logout', [LogoutController::class, 'logout']);
    Route::get('/auth/me', [ProfileController::class, 'me']);

    Route::put('/profile/complete', [ProfileController::class, 'complete']);
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::put('/profile/change-password', [ProfileController::class, 'changePassword']);
});

Route::middleware(['auth:sanctum', 'locale', 'citizen'])->group(function (): void {
    Route::get('/profile/status', [ProfileController::class, 'status']);
});

Route::middleware(['auth:sanctum', 'locale', 'citizen'])->prefix('applications')->group(function (): void {
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

Route::middleware(['auth:sanctum', 'locale', 'citizen'])->group(function (): void {
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
            ->middleware('throttle:10,1'); // DEPRECATED: use POST /applications with service_type_code=license_unblock
    });

    Route::get('/licenses', [LicenseController::class, 'index']);

    Route::get('/licenses/{license}', [LicenseController::class, 'show'])->whereNumber('license');

    Route::post('/licenses/{license}/download', [LicenseController::class, 'download'])
        ->whereNumber('license')
        ->middleware('throttle:15,1');

    Route::get('/fines', [FineController::class, 'index']);
    Route::get('/fines/{fine}', [FineController::class, 'show'])->whereNumber('fine');
    Route::get('/fines/{fine}/payments/{payment}/status', [FinePaymentController::class, 'status'])
        ->whereNumber('fine')
        ->whereNumber('payment');

    Route::get('/payments', [CitizenPaymentController::class, 'index']);
    Route::get('/payments/{payment}', [CitizenPaymentController::class, 'show'])->whereNumber('payment');

    Route::middleware('profile.approved')->group(function (): void {
        Route::post('/fines/{fine}/payments', [FinePaymentController::class, 'store'])
            ->whereNumber('fine')
            ->middleware('throttle:15,1');
        Route::post('/fines/{fine}/payments/{payment}/confirm', [FinePaymentController::class, 'confirm'])
            ->whereNumber('fine')
            ->whereNumber('payment')
            ->middleware('throttle:15,1');
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification');

    Route::post('/devices/push-token', [PushDeviceController::class, 'register']);
    Route::delete('/devices/push-token', [PushDeviceController::class, 'unregister']);

    Route::prefix('ai-agent')->group(function (): void {
        require base_path('app/Modules/AIAgent/Routes/ai-agent.php');
    });
});

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:100,1');

require base_path('app/Modules/Settings/Routes/settings.php');

require base_path('app/Modules/Content/Routes/content.php');

require base_path('app/Modules/Dashboard/Routes/dashboard.php');

require base_path('app/Modules/Admin/Routes/admin.php');

Route::prefix('chatbot')->group(function (): void {
});
