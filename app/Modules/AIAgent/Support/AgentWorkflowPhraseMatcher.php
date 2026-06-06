<?php

namespace App\Modules\AIAgent\Support;

use App\Modules\AIAgent\Enums\AgentIntent;

class AgentWorkflowPhraseMatcher
{
    /**
     * Resolve the highest-priority workflow intent from a user message.
     */
    public static function resolveIntent(
        string $message,
        ?string $lastSessionIntent = null,
        ?int $lastDiscussedApplicationId = null,
    ): ?AgentIntent {
        if (AgentSafetyRules::messageLooksAdminRelated($message)) {
            return AgentIntent::AdminActionDenied;
        }

        if (self::isOutOfScope($message)) {
            return AgentIntent::OutOfScope;
        }

        if (self::isRequiredDocumentsQuery($message)) {
            return AgentIntent::GetRequiredDocuments;
        }

        if (self::isApplicationStatusQuery($message)) {
            return AgentIntent::GetApplicationStatus;
        }

        if (self::isApplicationNextStepQuery($message, $lastSessionIntent, $lastDiscussedApplicationId)) {
            return AgentIntent::GetApplicationNextStep;
        }

        if (self::isPaymentFeeQuery($message)) {
            return self::isPayNowQuery($message)
                ? AgentIntent::StartPayment
                : AgentIntent::GetApplicationFee;
        }

        if (self::isAppointmentQuery($message)) {
            return self::isBookAppointmentQuery($message)
                ? AgentIntent::BookAppointment
                : AgentIntent::GetAppointmentSlots;
        }

        if (self::isTestResultsQuery($message)) {
            return AgentIntent::GetTestResults;
        }

        if (self::isFinesQuery($message)) {
            return AgentIntent::GetFines;
        }

        if (self::isLicensesQuery($message)) {
            return AgentIntent::GetLicenses;
        }

        if (self::isProfileStatusQuery($message)) {
            return AgentIntent::GetProfileStatus;
        }

        if (self::isExplicitNewLicenseRequest($message)) {
            return AgentIntent::CreateNewLicenseApplication;
        }

        return null;
    }

    public static function isWorkflowQuery(
        string $message,
        ?string $lastSessionIntent = null,
        ?int $lastDiscussedApplicationId = null,
    ): bool {
        $intent = self::resolveIntent($message, $lastSessionIntent, $lastDiscussedApplicationId);

        return $intent !== null && $intent !== AgentIntent::CreateNewLicenseApplication;
    }

    public static function blocksLicenseTypeExtraction(string $message): bool
    {
        $intent = self::resolveIntent($message);

        if ($intent !== null && $intent !== AgentIntent::CreateNewLicenseApplication) {
            return true;
        }

        $normalized = self::normalize($message);

        foreach (AgentMessageIntentMatcher::POSSESSIVE_CONTEXT_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isApplicationStatusQuery(string $message): bool
    {
        if (self::isRequiredDocumentsQuery($message) || self::isApplicationNextStepQuery($message)) {
            return false;
        }

        return AgentMessageIntentMatcher::isApplicationStatusQuery($message);
    }

    public static function isApplicationNextStepQuery(
        string $message,
        ?string $lastSessionIntent = null,
        ?int $lastDiscussedApplicationId = null,
    ): bool {
        if (self::isRequiredDocumentsQuery($message)) {
            return false;
        }

        return AgentMessageIntentMatcher::isApplicationNextStepQuery(
            $message,
            $lastSessionIntent,
            $lastDiscussedApplicationId
        );
    }

    public static function isRequiredDocumentsQuery(string $message): bool
    {
        return AgentMessageIntentMatcher::isRequiredDocumentsQuery($message);
    }

    public static function isExplicitNewLicenseRequest(string $message): bool
    {
        return AgentMessageIntentMatcher::isExplicitNewLicenseRequest($message);
    }

    public static function isPaymentFeeQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'بدي ادفع', 'بدي أدفع', 'ادفع الرسوم', 'أدفع الرسوم', 'الدفع', 'payment',
            'شو الرسوم', 'كم الرسوم', 'رسوم الطلب', 'application fee', 'pay fee',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isPayNowQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach (['بدي ادفع', 'بدي أدفع', 'ادفع', 'أدفع', 'pay now', 'start payment'] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isAppointmentQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'احجزلي موعد', 'احجز موعد', 'بدي احجز', 'بدي أحجز', 'المواعيد المتاحة',
            'شو المواعيد', 'appointment', 'book appointment', 'available slots',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isBookAppointmentQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach (['احجزلي', 'احجز موعد', 'بدي احجز', 'بدي أحجز', 'book appointment'] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isTestResultsQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'نتيجة الفحص', 'نتيجة الاختبار', 'شو نتيجة', 'نجحت', 'رسبت', 'test result',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isFinesQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'اعرضلي مخالفاتي', 'مخalفاتي', 'مخالفاتي', 'عندي مخalفات', 'عندي مخالفات',
            'الغرامات', 'الغرامه', 'fines', 'my fines',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isLicensesQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'اعرضلي رخصي', 'رخصتي', 'رخصي', 'تفاصيل الرخصة', 'my license', 'my licenses',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isProfileStatusQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'حالة البروفايل', 'حالة الملف', 'تمت الموافقة على بروفايلي', 'profile status',
            'ليش ما فيني أقدم', 'ليش ما فيني اقدم', 'why cant i apply',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isOutOfScope(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach (['weather', 'football', 'bitcoin', 'recipe', 'الطقس', 'كرة القدم', 'طبخ', 'crypto'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $message): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
    }
}
