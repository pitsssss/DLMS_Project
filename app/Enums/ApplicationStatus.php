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
}
