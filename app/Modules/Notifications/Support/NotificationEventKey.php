<?php

namespace App\Modules\Notifications\Support;

use App\Enums\NotificationType;
use Carbon\CarbonInterface;

/**
 * Deterministic event-instance keys for notification deduplication.
 */
final class NotificationEventKey
{
    public static function make(NotificationType $type, string $subject): string
    {
        return $type->value.':'.$subject;
    }

    public static function forUserAt(NotificationType $type, int $userId, CarbonInterface|string $at): string
    {
        return self::make($type, 'user:'.$userId.':at:'.self::timestamp($at));
    }

    public static function forApplication(NotificationType $type, int $applicationId): string
    {
        return self::make($type, 'application:'.$applicationId);
    }

    public static function forApplicationStatusHistory(NotificationType $type, int $historyId): string
    {
        return self::make($type, 'history:'.$historyId);
    }

    public static function forDocumentReview(NotificationType $type, int $documentId, CarbonInterface|string $reviewedAt): string
    {
        return self::make($type, 'document:'.$documentId.':review:'.self::timestamp($reviewedAt));
    }

    public static function forPayment(NotificationType $type, int $paymentId): string
    {
        return self::make($type, 'payment:'.$paymentId);
    }

    public static function forPaymentCode(NotificationType $type, int $paymentId, string $code): string
    {
        return self::make($type, 'payment:'.$paymentId.':code:'.$code);
    }

    public static function forTestResult(NotificationType $type, int $testResultId): string
    {
        return self::make($type, 'test_result:'.$testResultId);
    }

    public static function forLicense(NotificationType $type, int $licenseId): string
    {
        return self::make($type, 'license:'.$licenseId);
    }

    public static function forLicenseAt(NotificationType $type, int $licenseId, CarbonInterface|string $at): string
    {
        return self::make($type, 'license:'.$licenseId.':at:'.self::timestamp($at));
    }

    public static function forFine(NotificationType $type, int $fineId): string
    {
        return self::make($type, 'fine:'.$fineId);
    }

    public static function forAppointment(NotificationType $type, int $appointmentId): string
    {
        return self::make($type, 'appointment:'.$appointmentId);
    }

    public static function forAppointmentReschedule(
        int $appointmentId,
        int $slotId,
        CarbonInterface|string $scheduledAt
    ): string {
        return self::make(
            NotificationType::AppointmentRescheduled,
            'appointment:'.$appointmentId.':slot:'.$slotId.':at:'.self::timestamp($scheduledAt)
        );
    }

    private static function timestamp(CarbonInterface|string $at): string
    {
        if ($at instanceof CarbonInterface) {
            return (string) $at->getTimestamp();
        }

        return (string) strtotime($at);
    }
}
