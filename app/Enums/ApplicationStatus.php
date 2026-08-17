<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case DocumentsUnderReview = 'documents_under_review';
    case DocumentsRejected = 'documents_rejected';
    case PaymentPending = 'payment_pending';
    case PaymentCompleted = 'payment_completed';
    case AppointmentPending = 'appointment_pending';
    case InTesting = 'in_testing';
    case WaitingRetest = 'waiting_retest';
    case Approved = 'approved';
    case LicenseIssued = 'license_issued';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case AdministrativeReview = 'administrative_review';
    case Completed = 'completed';

    /**
     * @return list<self>
     */
    public static function terminalCases(): array
    {
        return [
            self::Rejected,
            self::Cancelled,
            self::LicenseIssued,
            self::Completed,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_map(
            static fn (self $status) => $status->value,
            self::terminalCases()
        );
    }

    public static function activeCases(): array
    {
        return [
            self::Draft,
            self::DocumentsUnderReview,
            self::DocumentsRejected,
            self::PaymentPending,
            self::PaymentCompleted,
            self::AppointmentPending,
            self::InTesting,
            self::WaitingRetest,
            self::Approved,
            self::AdministrativeReview,
        ];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $status) => $status->value,
            self::activeCases()
        );
    }

    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminalCases(), true);
    }
}
