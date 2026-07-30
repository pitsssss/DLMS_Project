<?php

use App\Modules\Dashboard\Controllers\DashboardAccessControlController;
use App\Modules\Content\Controllers\DashboardContactMessageController;
use App\Modules\Dashboard\Controllers\DashboardApplicationController;
use App\Modules\Dashboard\Controllers\DashboardAuthController;
use App\Modules\Dashboard\Controllers\DashboardCitizenController;
use App\Modules\Dashboard\Controllers\DashboardDocumentReviewController;
use App\Modules\Dashboard\Controllers\DashboardEmployeeController;
use App\Modules\Dashboard\Controllers\DashboardAppointmentSlotController;
use App\Modules\Dashboard\Controllers\DashboardFeeController;
use App\Modules\Dashboard\Controllers\DashboardLicenseTypeController;
use App\Modules\Dashboard\Controllers\DashboardIssuedLicenseController;
use App\Modules\Dashboard\Controllers\DashboardOverviewController;
use App\Modules\Dashboard\Controllers\DashboardPaymentController;
use App\Modules\Dashboard\Controllers\DashboardReportController;
use App\Modules\Dashboard\Controllers\DashboardRoleController;
use App\Modules\Dashboard\Controllers\DashboardServiceTypeController;
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
        Route::get('/overview', DashboardOverviewController::class);

        Route::prefix('reports')
            ->middleware('permission:view_reports')
            ->group(function (): void {
                Route::get('/options', [DashboardReportController::class, 'options']);
                Route::get('/summary', [DashboardReportController::class, 'summary']);

                Route::middleware('permission:view_applications,manage_applications')->group(function (): void {
                    Route::get('/applications', [DashboardReportController::class, 'applications']);
                });

                Route::middleware('permission:record_test_result,manage_appointments,view_appointments')->group(function (): void {
                    Route::get('/tests', [DashboardReportController::class, 'tests']);
                });

                Route::middleware('permission:view_appointments,manage_appointments')->group(function (): void {
                    Route::get('/appointments', [DashboardReportController::class, 'appointments']);
                });

                Route::middleware('permission:view_licenses,manage_licenses,issue_license')->group(function (): void {
                    Route::get('/licenses', [DashboardReportController::class, 'licenses']);
                });

                Route::middleware('permission:view_fines,manage_fines')->group(function (): void {
                    Route::get('/fines', [DashboardReportController::class, 'fines']);
                });

                Route::middleware('permission:manage_employees,view_employees')->group(function (): void {
                    Route::get('/employees', [DashboardReportController::class, 'employees']);
                });
            });

        // Appointment times management (slot availability)
        Route::middleware('permission:view_appointments,manage_appointments')->group(function (): void {
            Route::get('/appointment-slots', [DashboardAppointmentSlotController::class, 'index']);
            Route::get('/appointment-slots/stats', [DashboardAppointmentSlotController::class, 'stats']);
            Route::get('/appointment-slots/options', [DashboardAppointmentSlotController::class, 'options']);
            Route::get('/appointment-slots/{slot}', [DashboardAppointmentSlotController::class, 'show'])->whereNumber('slot');
            Route::get('/appointment-slots/{slot}/bookings', [DashboardAppointmentSlotController::class, 'bookings'])->whereNumber('slot');
            Route::get('/appointment-slots/{slot}/audit-logs', [DashboardAppointmentSlotController::class, 'auditLogs'])->whereNumber('slot');
        });

        Route::middleware('permission:manage_appointments')->group(function (): void {
            Route::post('/appointment-slots', [DashboardAppointmentSlotController::class, 'store']);
            Route::patch('/appointment-slots/{slot}', [DashboardAppointmentSlotController::class, 'update'])->whereNumber('slot');
            Route::patch('/appointment-slots/{slot}/activate', [DashboardAppointmentSlotController::class, 'activate'])->whereNumber('slot');
            Route::patch('/appointment-slots/{slot}/deactivate', [DashboardAppointmentSlotController::class, 'deactivate'])->whereNumber('slot');
        });

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

        // Financial operations — application payments only (fines remain separate)
        Route::middleware('permission:view_payments,manage_payments')->group(function (): void {
            Route::get('/payments', [DashboardPaymentController::class, 'index']);
            Route::get('/payments/stats', [DashboardPaymentController::class, 'stats']);
            Route::get('/payments/options', [DashboardPaymentController::class, 'options']);
            Route::get('/payments/due-fees', [DashboardPaymentController::class, 'dueFees']);
            Route::get('/payments/{payment}', [DashboardPaymentController::class, 'show'])->whereNumber('payment');
            Route::get('/payments/{payment}/attempts', [DashboardPaymentController::class, 'attempts'])->whereNumber('payment');
            Route::get('/payments/{payment}/audit-logs', [DashboardPaymentController::class, 'auditLogs'])->whereNumber('payment');
        });

        Route::middleware('permission:manage_payments')->group(function (): void {
            Route::post('/payments/{payment}/verify', [DashboardPaymentController::class, 'verify'])
                ->whereNumber('payment')
                ->middleware('throttle:30,1');
        });

        // Citizen management (citizens register themselves; Dashboard can view, update status and audit)
        Route::middleware('permission:manage_users')->group(function (): void {
            Route::get('/citizens', [DashboardCitizenController::class, 'index']);
            Route::get('/citizens/stats', [DashboardCitizenController::class, 'stats']);
            Route::get('/citizens/search', [DashboardCitizenController::class, 'search']); // deprecated – use ?search= on /citizens
            Route::get('/citizens/profile-statuses', [DashboardCitizenController::class, 'profileStatuses']);
            Route::get('/citizens/{citizen}', [DashboardCitizenController::class, 'show'])->whereNumber('citizen');
            Route::put('/citizens/{citizen}', [DashboardCitizenController::class, 'update'])->whereNumber('citizen');
            Route::post('/citizens/{citizen}/activate', [DashboardCitizenController::class, 'activate'])->whereNumber('citizen');
            Route::post('/citizens/{citizen}/deactivate', [DashboardCitizenController::class, 'deactivate'])->whereNumber('citizen');
            Route::get('/citizens/{citizen}/applications', [DashboardCitizenController::class, 'applications'])->whereNumber('citizen');
            Route::get('/citizens/{citizen}/licenses', [DashboardCitizenController::class, 'licenses'])->whereNumber('citizen');
            Route::get('/citizens/{citizen}/fines', [DashboardCitizenController::class, 'fines'])->whereNumber('citizen');
            Route::get('/citizens/{citizen}/audit-logs', [DashboardCitizenController::class, 'auditLogs'])->whereNumber('citizen');
        });

        // Partner: document reviews
        Route::middleware('permission:review_documents')->group(function (): void {
            Route::get('/document-reviews', [DashboardDocumentReviewController::class, 'index']);
            Route::get('/document-reviews/stats', [DashboardDocumentReviewController::class, 'stats']);
            Route::get('/document-reviews/{application}', [DashboardDocumentReviewController::class, 'show'])->whereNumber('application');
            Route::get('/document-reviews/documents/{document}/preview', [DashboardDocumentReviewController::class, 'preview'])->whereNumber('document');
            Route::post('/document-reviews/documents/{document}/approve', [DashboardDocumentReviewController::class, 'approve'])
                ->whereNumber('document')
                ->middleware('throttle:60,1');
            Route::post('/document-reviews/documents/{document}/reject', [DashboardDocumentReviewController::class, 'reject'])
                ->whereNumber('document')
                ->middleware('throttle:60,1');
        });

        // Yours: employees
        Route::middleware('permission:manage_employees')->group(function (): void {
            Route::get('/employees', [DashboardEmployeeController::class, 'index']);
            Route::post('/employees', [DashboardEmployeeController::class, 'store']);
            Route::get('/employees/{user}', [DashboardEmployeeController::class, 'show'])->whereNumber('user');
            Route::put('/employees/{user}', [DashboardEmployeeController::class, 'update'])->whereNumber('user');
            Route::patch('/employees/{user}/activate', [DashboardEmployeeController::class, 'activate'])->whereNumber('user');
            Route::patch('/employees/{user}/deactivate', [DashboardEmployeeController::class, 'deactivate'])->whereNumber('user');
            Route::patch('/employees/{user}/toggle-active', [DashboardEmployeeController::class, 'toggleActive'])->whereNumber('user');
            Route::post('/employees/{user}/reset-password', [DashboardEmployeeController::class, 'resetPassword'])->whereNumber('user');
            Route::post('/employees/{user}/assign-role', [DashboardEmployeeController::class, 'assignRole'])->whereNumber('user');
        });

        // Applications (shared)
        Route::middleware('permission:view_applications')->group(function (): void {
            Route::get('/applications', [DashboardApplicationController::class, 'index']);
            // Lookup application details by application number (not internal id). The table shows application_number.
            Route::get('/applications/{application_number}', [DashboardApplicationController::class, 'show'])
                ->where('application_number', '[A-Za-z0-9_\-]+');
        });

        // Issued licenses management
        Route::middleware('permission:view_licenses,manage_licenses')->group(function (): void {
            Route::get('/licenses', [DashboardIssuedLicenseController::class, 'index']);
            Route::get('/licenses/stats', [DashboardIssuedLicenseController::class, 'stats']);
            Route::get('/licenses/options', [DashboardIssuedLicenseController::class, 'options']);
            Route::get('/licenses/{license}', [DashboardIssuedLicenseController::class, 'show'])->whereNumber('license');
            Route::get('/licenses/{license}/history', [DashboardIssuedLicenseController::class, 'history'])->whereNumber('license');
            Route::get('/licenses/{license}/audit-logs', [DashboardIssuedLicenseController::class, 'auditLogs'])->whereNumber('license');
            Route::post('/licenses/{license}/print', [DashboardIssuedLicenseController::class, 'print'])
                ->whereNumber('license')
                ->middleware('throttle:30,1');
        });

        Route::middleware('permission:manage_licenses')->group(function (): void {
            Route::post('/licenses/{license}/block', [DashboardIssuedLicenseController::class, 'block'])
                ->whereNumber('license')
                ->middleware('throttle:60,1');
            Route::post('/licenses/{license}/unblock', [DashboardIssuedLicenseController::class, 'unblock'])
                ->whereNumber('license')
                ->middleware('throttle:60,1');
        });

        // Yours: license types + service types settings
        Route::middleware('permission:manage_settings')->group(function (): void {
            Route::get('/license-types', [DashboardLicenseTypeController::class, 'index']);
            Route::post('/license-types', [DashboardLicenseTypeController::class, 'store']);
            Route::get('/license-types/{licenseType}', [DashboardLicenseTypeController::class, 'show'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}', [DashboardLicenseTypeController::class, 'update'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}/activate', [DashboardLicenseTypeController::class, 'activate'])->whereNumber('licenseType');
            Route::patch('/license-types/{licenseType}/deactivate', [DashboardLicenseTypeController::class, 'deactivate'])->whereNumber('licenseType');

            Route::get('/service-types', [DashboardServiceTypeController::class, 'index']);
            Route::post('/service-types', [DashboardServiceTypeController::class, 'store']);
            Route::get('/service-types/{serviceType}', [DashboardServiceTypeController::class, 'show'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}', [DashboardServiceTypeController::class, 'update'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}/activate', [DashboardServiceTypeController::class, 'activate'])->whereNumber('serviceType');
            Route::patch('/service-types/{serviceType}/deactivate', [DashboardServiceTypeController::class, 'deactivate'])->whereNumber('serviceType');

            Route::get('/fees', [DashboardFeeController::class, 'index']);
            Route::get('/fees/stats', [DashboardFeeController::class, 'stats']);
            Route::get('/fees/options', [DashboardFeeController::class, 'options']);
            Route::post('/fees', [DashboardFeeController::class, 'store']);
            Route::get('/fees/{fee}', [DashboardFeeController::class, 'show'])->whereNumber('fee');
            Route::patch('/fees/{fee}', [DashboardFeeController::class, 'update'])->whereNumber('fee');
            Route::patch('/fees/{fee}/activate', [DashboardFeeController::class, 'activate'])->whereNumber('fee');
            Route::patch('/fees/{fee}/deactivate', [DashboardFeeController::class, 'deactivate'])->whereNumber('fee');
            Route::get('/fees/{fee}/audit-logs', [DashboardFeeController::class, 'auditLogs'])->whereNumber('fee');
        });

        // Access-control management — genuine Super Admin only (not manage_roles alone).
        Route::prefix('access-control')
            ->middleware('super_admin')
            ->group(function (): void {
                Route::get('/overview', [DashboardAccessControlController::class, 'overview']);
                Route::get('/permissions', [DashboardAccessControlController::class, 'permissions']);

                Route::get('/roles', [DashboardAccessControlController::class, 'roles']);
                Route::get('/roles/options', [DashboardAccessControlController::class, 'roleOptions']);
                Route::post('/roles', [DashboardAccessControlController::class, 'storeRole']);
                Route::get('/roles/{role}', [DashboardAccessControlController::class, 'showRole'])->whereNumber('role');
                Route::patch('/roles/{role}', [DashboardAccessControlController::class, 'updateRole'])->whereNumber('role');
                Route::patch('/roles/{role}/permissions', [DashboardAccessControlController::class, 'syncRolePermissions'])->whereNumber('role');
                Route::patch('/roles/{role}/archive', [DashboardAccessControlController::class, 'archiveRole'])->whereNumber('role');
                Route::patch('/roles/{role}/restore', [DashboardAccessControlController::class, 'restoreRole'])->whereNumber('role');
                Route::get('/roles/{role}/employees', [DashboardAccessControlController::class, 'roleEmployees'])->whereNumber('role');
                Route::get('/roles/{role}/audit-logs', [DashboardAccessControlController::class, 'roleAuditLogs'])->whereNumber('role');
            });

        Route::middleware('super_admin')->group(function (): void {
            Route::get('/employees/{employee}/access', [DashboardAccessControlController::class, 'employeeAccess'])->whereNumber('employee');
            Route::patch('/employees/{employee}/roles', [DashboardAccessControlController::class, 'syncEmployeeRole'])->whereNumber('employee');
            Route::patch('/employees/{employee}/direct-permissions', [DashboardAccessControlController::class, 'syncDirectPermissions'])->whereNumber('employee');
            Route::get('/employees/{employee}/access/audit-logs', [DashboardAccessControlController::class, 'employeeAccessAuditLogs'])->whereNumber('employee');
        });
    });
