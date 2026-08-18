<?php

namespace App\Enums;

/**
 * Machine contract for in-app notification `type` values.
 * Values are stable API strings — never localized text.
 */
enum NotificationType: string
{
    case ProfileApproved = 'profile.approved';
    case ProfileRejected = 'profile.rejected';

    case AccountActivated = 'account.activated';
    case AccountDeactivated = 'account.deactivated';

    case ApplicationCreated = 'application.created';
    case ApplicationDocumentsUnderReview = 'application.documents_under_review';
    case ApplicationDocumentsRejected = 'application.documents_rejected';
    case ApplicationPaymentPending = 'application.payment_pending';
    case ApplicationAppointmentPending = 'application.appointment_pending';
    case ApplicationApproved = 'application.approved';
    case ApplicationWaitingRetest = 'application.waiting_retest';
    case ApplicationAdministrativeReview = 'application.administrative_review';
    case ApplicationRejected = 'application.rejected';
    case ApplicationCancelled = 'application.cancelled';
    case ApplicationCompleted = 'application.completed';

    /**
     * Legacy type retained for historical rows only.
     * New license issuances emit {@see self::LicenseIssued} exclusively.
     */
    case ApplicationLicenseIssued = 'application.license_issued';

    case DocumentApproved = 'document.approved';
    case DocumentRejected = 'document.rejected';

    case PaymentCompleted = 'payment.completed';
    case PaymentFailed = 'payment.failed';
    case PaymentUnderVerification = 'payment.under_verification';

    case AppointmentBooked = 'appointment.booked';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCancelled = 'appointment.cancelled';

    case TestResultPassed = 'test_result.passed';
    case TestResultFailed = 'test_result.failed';
    case TestResultNoShow = 'test_result.no_show';

    case LicenseIssued = 'license.issued';
    case LicenseBlocked = 'license.blocked';
    case LicenseUnblocked = 'license.unblocked';
    case LicenseExpired = 'license.expired';

    case FineCreated = 'fine.created';
    case FinePaid = 'fine.paid';
    case FineCancelled = 'fine.cancelled';

    public function domain(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function titleKey(): string
    {
        return match ($this) {
            self::ProfileApproved => 'messages.notifications.profile_approved_title',
            self::ProfileRejected => 'messages.notifications.profile_rejected_title',
            self::AccountActivated => 'messages.notifications.account_activated_title',
            self::AccountDeactivated => 'messages.notifications.account_deactivated_title',
            self::ApplicationCreated => 'messages.notifications.application_created_title',
            self::ApplicationDocumentsUnderReview => 'messages.notifications.documents_under_review_title',
            self::ApplicationDocumentsRejected => 'messages.notifications.documents_rejected_title',
            self::ApplicationPaymentPending => 'messages.notifications.payment_required_title',
            self::ApplicationAppointmentPending => 'messages.notifications.appointment_pending_title',
            self::ApplicationApproved => 'messages.notifications.approved_title',
            self::ApplicationWaitingRetest => 'messages.notifications.retest_title',
            self::ApplicationAdministrativeReview => 'messages.notifications.admin_review_title',
            self::ApplicationRejected => 'messages.notifications.application_rejected_title',
            self::ApplicationCancelled => 'messages.notifications.application_cancelled_title',
            self::ApplicationCompleted => 'messages.notifications.application_completed_title',
            self::ApplicationLicenseIssued, self::LicenseIssued => 'messages.notifications.license_issued_title',
            self::DocumentApproved => 'messages.notifications.document_approved_title',
            self::DocumentRejected => 'messages.notifications.document_rejected_title',
            self::PaymentCompleted => 'messages.notifications.payment_completed_title',
            self::PaymentFailed => 'messages.notifications.payment_failed_title',
            self::PaymentUnderVerification => 'messages.notifications.payment_under_verification_title',
            self::AppointmentBooked => 'messages.notifications.appointment_booked_title',
            self::AppointmentRescheduled => 'messages.notifications.appointment_rescheduled_title',
            self::AppointmentCancelled => 'messages.notifications.appointment_cancelled_title',
            self::TestResultPassed, self::TestResultFailed, self::TestResultNoShow => 'messages.notifications.test_result_title',
            self::LicenseBlocked => 'messages.notifications.license_blocked_title',
            self::LicenseUnblocked => 'messages.notifications.license_unblocked_title',
            self::LicenseExpired => 'messages.notifications.license_expired_title',
            self::FineCreated => 'messages.notifications.fine_issued_title',
            self::FinePaid => 'messages.notifications.fine_paid_title',
            self::FineCancelled => 'messages.notifications.fine_cancelled_title',
        };
    }

    public function bodyKey(): string
    {
        return match ($this) {
            self::ProfileApproved => 'messages.notifications.profile_approved_body',
            self::ProfileRejected => 'messages.notifications.profile_rejected_body',
            self::AccountActivated => 'messages.notifications.account_activated_body',
            self::AccountDeactivated => 'messages.notifications.account_deactivated_body',
            self::ApplicationCreated => 'messages.notifications.application_created_body',
            self::ApplicationDocumentsUnderReview => 'messages.notifications.documents_under_review_body',
            self::ApplicationDocumentsRejected => 'messages.notifications.documents_rejected_body',
            self::ApplicationPaymentPending => 'messages.notifications.payment_required_body',
            self::ApplicationAppointmentPending => 'messages.notifications.appointment_pending_body',
            self::ApplicationApproved => 'messages.notifications.approved_body',
            self::ApplicationWaitingRetest => 'messages.notifications.retest_body',
            self::ApplicationAdministrativeReview => 'messages.notifications.admin_review_body',
            self::ApplicationRejected => 'messages.notifications.application_rejected_body',
            self::ApplicationCancelled => 'messages.notifications.application_cancelled_body',
            self::ApplicationCompleted => 'messages.notifications.application_completed_body',
            self::ApplicationLicenseIssued, self::LicenseIssued => 'messages.notifications.license_issued_body',
            self::DocumentApproved => 'messages.notifications.document_approved_body',
            self::DocumentRejected => 'messages.notifications.document_rejected_body',
            self::PaymentCompleted => 'messages.notifications.payment_completed_body',
            self::PaymentFailed => 'messages.notifications.payment_failed_body',
            self::PaymentUnderVerification => 'messages.notifications.payment_under_verification_body',
            self::AppointmentBooked => 'messages.notifications.appointment_booked_body',
            self::AppointmentRescheduled => 'messages.notifications.appointment_rescheduled_body',
            self::AppointmentCancelled => 'messages.notifications.appointment_cancelled_body',
            self::TestResultPassed, self::TestResultFailed, self::TestResultNoShow => 'messages.notifications.test_result_body',
            self::LicenseBlocked => 'messages.notifications.license_blocked_body',
            self::LicenseUnblocked => 'messages.notifications.license_unblocked_body',
            self::LicenseExpired => 'messages.notifications.license_expired_body',
            self::FineCreated => 'messages.notifications.fine_issued_body',
            self::FinePaid => 'messages.notifications.fine_paid_body',
            self::FineCancelled => 'messages.notifications.fine_cancelled_body',
        };
    }

    /**
     * @return list<string>
     */
    public function allowedDataKeys(): array
    {
        return match ($this) {
            self::ProfileApproved => ['profile_status'],
            self::ProfileRejected => ['profile_status', 'rejection_reason'],
            self::AccountActivated, self::AccountDeactivated => [],
            self::ApplicationCreated => ['application_id'],
            self::ApplicationDocumentsUnderReview,
            self::ApplicationDocumentsRejected,
            self::ApplicationPaymentPending,
            self::ApplicationAppointmentPending,
            self::ApplicationApproved,
            self::ApplicationWaitingRetest,
            self::ApplicationAdministrativeReview,
            self::ApplicationRejected,
            self::ApplicationCancelled,
            self::ApplicationCompleted,
            self::ApplicationLicenseIssued => ['application_id', 'application_number', 'status'],
            self::DocumentApproved => ['application_id', 'document_id'],
            self::DocumentRejected => [
                'application_id',
                'document_id',
                'rejection_reason_code',
                'rejection_reason_label',
                'rejection_details',
            ],
            self::PaymentCompleted => [
                'application_id',
                'fine_id',
                'payment_id',
                'payment_number',
                'amount',
                'currency',
            ],
            self::PaymentFailed, self::PaymentUnderVerification => [
                'application_id',
                'fine_id',
                'payment_id',
                'payment_number',
            ],
            self::AppointmentBooked, self::AppointmentRescheduled, self::AppointmentCancelled => [
                'application_id',
                'appointment_id',
                'test_type_id',
            ],
            self::TestResultPassed, self::TestResultFailed, self::TestResultNoShow => [
                'application_id',
                'test_result_id',
            ],
            self::LicenseIssued => ['application_id', 'license_id'],
            self::LicenseBlocked => ['license_id', 'license_number'],
            self::LicenseUnblocked, self::LicenseExpired => ['license_id', 'license_number'],
            self::FineCreated, self::FinePaid, self::FineCancelled => ['fine_id'],
        };
    }

    public function isLegacyEmissionSuppressed(): bool
    {
        return $this === self::ApplicationLicenseIssued;
    }

    public static function tryFromApplicationStatus(ApplicationStatus $status): ?self
    {
        return match ($status) {
            ApplicationStatus::PaymentPending => self::ApplicationPaymentPending,
            ApplicationStatus::DocumentsRejected => self::ApplicationDocumentsRejected,
            ApplicationStatus::DocumentsUnderReview => self::ApplicationDocumentsUnderReview,
            ApplicationStatus::AppointmentPending => self::ApplicationAppointmentPending,
            ApplicationStatus::Approved => self::ApplicationApproved,
            ApplicationStatus::WaitingRetest => self::ApplicationWaitingRetest,
            ApplicationStatus::AdministrativeReview => self::ApplicationAdministrativeReview,
            ApplicationStatus::Rejected => self::ApplicationRejected,
            ApplicationStatus::Cancelled => self::ApplicationCancelled,
            ApplicationStatus::Completed => self::ApplicationCompleted,
            // LicenseIssued: emit license.issued only (via LicenseService).
            ApplicationStatus::LicenseIssued => null,
            default => null,
        };
    }

    public static function fromTestResultStatus(TestResultStatus $status): self
    {
        return match ($status) {
            TestResultStatus::Passed => self::TestResultPassed,
            TestResultStatus::Failed => self::TestResultFailed,
            TestResultStatus::NoShow => self::TestResultNoShow,
            TestResultStatus::Pending => throw new \InvalidArgumentException(
                'Pending test results do not emit citizen notifications.'
            ),
        };
    }
}
