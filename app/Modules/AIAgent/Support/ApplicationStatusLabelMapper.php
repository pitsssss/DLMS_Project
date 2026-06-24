<?php

namespace App\Modules\AIAgent\Support;

use App\Enums\ApplicationStatus;

class ApplicationStatusLabelMapper
{
    public static function labelAr(ApplicationStatus|string|null $status): string
    {
        $value = $status instanceof ApplicationStatus ? $status->value : (string) $status;

        return match ($value) {
            ApplicationStatus::Draft->value => 'مسودة',
            ApplicationStatus::DocumentsUnderReview->value => 'قيد مراجعة الوثائق',
            ApplicationStatus::DocumentsRejected->value => 'تم رفض الوثائق',
            ApplicationStatus::PaymentPending->value => 'بانتظار الدفع',
            ApplicationStatus::PaymentCompleted->value => 'تم الدفع',
            ApplicationStatus::AppointmentPending->value => 'بانتظار حجز موعد',
            ApplicationStatus::InTesting->value => 'قيد الاختبارات',
            ApplicationStatus::WaitingRetest->value => 'بانتظار إعادة الاختبار',
            ApplicationStatus::Approved->value => 'مؤهل لإصدار الرخصة',
            ApplicationStatus::AdministrativeReview->value => 'قيد المراجعة الإدارية',
            ApplicationStatus::LicenseIssued->value => 'تم إصدار الرخصة',
            ApplicationStatus::Rejected->value => 'مرفوض',
            ApplicationStatus::Cancelled->value => 'ملغى',
            default => $value !== '' ? $value : 'غير معروف',
        };
    }
}
