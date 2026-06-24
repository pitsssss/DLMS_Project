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

        if (self::isCurrentAppointmentsQuery($message)) {
            return AgentIntent::GetCurrentAppointments;
        }

        if (self::isAvailableTestsQuery($message)) {
            return AgentIntent::GetAvailableTests;
        }

        if (self::isAppointmentSlotsQuery($message)) {
            return AgentIntent::GetAppointmentSlots;
        }

        if (self::isBookAppointmentQuery($message)) {
            return AgentIntent::BookAppointment;
        }

        if (self::isTestResultsQuery($message)) {
            return AgentIntent::GetTestResults;
        }

        if (self::isFinesQuery($message)) {
            return AgentIntent::GetFines;
        }

        if (self::isRenewLicenseRequest($message)) {
            return AgentIntent::CreateRenewLicenseApplication;
        }

        if (self::isLostReplacementRequest($message)) {
            return AgentIntent::CreateLostReplacementApplication;
        }

        if (self::isDamagedReplacementRequest($message)) {
            return AgentIntent::CreateDamagedReplacementApplication;
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

    public static function isRenewLicenseRequest(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'بدي جدد رخصتي', 'تجديد رخصة', 'رخصتي قربت تنتهي', 'جددلي الرخصة', 'renew my license',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isLostReplacementRequest(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'ضاعت رخصتي', 'فقدت رخصتي', 'بدي بدل فاقد', 'رخصتي مفقودة', 'lost license replacement',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isDamagedReplacementRequest(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'رخصتي تالفة', 'الرخصة خربانة', 'بدي بدل تالف', 'الرخصة مكسورة', 'damaged license replacement',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
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

    public static function isAvailableTestsQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'شو الفحوص المتاحة',
            'شو الفحوصات المتاحة',
            'شو الاختبارات المتاحة',
            'شو الاختبار المتاح',
            'شو الفحص المتاح',
            'اعرض الفحوص المتاحة',
            'اعرض الاختبارات المتاحة',
            'اعرض الفحوصات المتاحة',
            'ما هي الاختبارات المتاحة',
            'ما الاختبارات المتاحة',
            'شو في فحوصات متاحة',
            'أي فحص لازم أحجز',
            'أي فحص لازم احجز',
            'أي اختبار لازم أحجز',
            'أي اختبار لازم احجز',
            'أي فحص عليي هلق',
            'أي فحص علي هلق',
            'شو الاختبار الحالي',
            'شو الفحص الحالي',
            'available tests',
            'what tests are available',
            'current test',
            'which test should i book',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isAppointmentSlotsQuery(string $message): bool
    {
        if (self::isAvailableTestsQuery($message)) {
            return false;
        }

        $normalized = self::normalize($message);

        foreach ([
            'المواعيد المتاحة',
            'شو المواعيد',
            'اعرض المواعيد',
            'مواعيد اختبار',
            'مواعيد فحص',
            'available slots',
            'show appointment slots',
            'show available slots',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (AgentTestTypeExtractor::extractFromMessage($message) !== null
            && str_contains($normalized, self::normalize('مواعيد'))) {
            return true;
        }

        return false;
    }

    public static function isVagueBookAppointmentQuery(string $message): bool
    {
        if (self::isBookFirstAvailableSlotQuery($message)) {
            return false;
        }

        if (self::isAppointmentSlotsQuery($message) || self::isAvailableTestsQuery($message)) {
            return false;
        }

        $normalized = self::normalize($message);

        foreach ([
            'احجزلي موعد',
            'احجز موعد',
            'بدي احجز موعد',
            'بدي أحجز موعد',
            'book appointment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isBookFirstAvailableSlotQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'احجز أول موعد',
            'احجزلي الموعد الأول',
            'احجز اول موعد',
            'احجزلي اول موعد',
            'أول موعد متاح',
            'اول موعد متاح',
            'book first appointment',
            'book first slot',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isCurrentAppointmentsQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'حجزتلي موعد',
            'تم حجز الموعد',
            'انحجز الموعد',
            'حجزت موعد',
            'عندي موعد محجوز',
            'عندي موعد',
            'شو موعدي',
            'متى موعدي',
            'وقت موعدي',
            'وين موعدي',
            'اعرضلي موعدي',
            'اعرض المواعيد المحجوزة',
            'شو الموعد اللي حجزته',
            'تأكدلي إذا انحجز الموعد',
            'صار عندي موعد',
            'did you book an appointment',
            'is my appointment booked',
            'show my appointment',
            'when is my appointment',
            'my booked appointment',
            'appointment status',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (preg_match('/(?:حجزت|انحجز|تم\s+حجز).{0,20}موعد/u', $normalized)
            && (str_contains($normalized, '?') || str_contains($normalized, '؟'))) {
            return true;
        }

        return false;
    }

    public static function isAppointmentQuery(string $message): bool
    {
        return self::isCurrentAppointmentsQuery($message)
            || self::isAppointmentSlotsQuery($message)
            || self::isBookAppointmentQuery($message)
            || self::isVagueBookAppointmentQuery($message)
            || self::isBookFirstAvailableSlotQuery($message);
    }

    public static function isBookAppointmentQuery(string $message): bool
    {
        if (self::isCurrentAppointmentsQuery($message)) {
            return false;
        }

        if (self::isVagueBookAppointmentQuery($message) || self::isBookFirstAvailableSlotQuery($message)) {
            return true;
        }

        $normalized = self::normalize($message);

        foreach ([
            'احجزلي',
            'احجز موعد',
            'بدي احجز',
            'بدي أحجز',
            'book appointment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (AgentTestTypeExtractor::extractFromMessage($message) !== null
            && (str_contains($normalized, 'احجز') || str_contains($normalized, 'book'))) {
            return true;
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
