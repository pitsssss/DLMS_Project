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

        if (self::isSubmitDocumentsForReviewQuery($message)) {
            return AgentIntent::SubmitDocumentsForReview;
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

        if (self::isPaymentStatusQuery($message)) {
            return AgentIntent::GetPaymentStatus;
        }

        if (self::isPaymentFeeQuery($message)) {
            return self::isPayNowQuery($message)
                ? AgentIntent::StartPayment
                : AgentIntent::GetApplicationFee;
        }

        if (self::isCurrentAppointmentsQuery($message)) {
            return AgentIntent::GetCurrentAppointments;
        }

        if (self::isCancelAppointmentQuery($message)) {
            return AgentIntent::CancelAppointment;
        }

        if (self::isRescheduleAppointmentQuery($message)) {
            return AgentIntent::RescheduleAppointment;
        }

        if (self::isRetestQuery($message)) {
            return AgentIntent::GetAvailableTests;
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

    public static function isSubmitDocumentsForReviewQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        // Intentionally restrictive to avoid colliding with "get required documents" queries.
        // We trigger when the user indicates they are sending/finished uploading documents for review.
        $patterns = [
            // Arabic explicit actions
            'ارسل الوثائق للمراجعة',
            'أرسل الوثائق للمراجعة',
            'قدم الوثائق للمراجعة',
            'ابعت الوثائق للمراجعة',
            'ابعت الأوراق للمراجعة',
            'قدم الأوراق للمراجعة',
            // English explicit actions
            'submit documents for review',
            'send documents for review',
            'submit documents',
            'send documents',
            'submit for review',
            'i submitted documents',
            'i sent documents',
            // Arabic "I finished uploading" variants (without triggering the generic "required documents" intent)
            'خلصت رفع',
            'خلصت رفع الوثائق',
            'خلصت رفع الاوراق',
            'خلصت رافع',
            'خلصت اوراق',
            'رفعت الوثائق',
            'رفعت الاوراق',
            'خلصت الوثائق',
            // English "I finished uploading" variants
            'finished uploading',
            'done uploading',
            'uploaded documents',
            'uploaded all documents',
            'i uploaded',
            'completed upload',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, self::normalize($pattern))) {
                return true;
            }
        }

        // Additional keyword-based match for shorter variants.
        return (str_contains($normalized, self::normalize('submit documents'))
                || str_contains($normalized, self::normalize('send documents')))
            && (str_contains($normalized, self::normalize('review'))
                || str_contains($normalized, self::normalize('للمراجعة')));
    }

    public static function isExplicitNewLicenseRequest(string $message): bool
    {
        return AgentMessageIntentMatcher::isExplicitNewLicenseRequest($message);
    }

    public static function isRenewLicenseRequest(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            // Arabic
            'بدي جدد رخصتي',
            'تجديد رخصة',
            'رخصتي قربت تنتهي',
            'جددلي الرخصة',
            'بدي جدد',
            'تجديد',
            'جدد رخصة',
            'أجدد رخصتي',
            'اجدد رخصتي',
            // English
            'renew my license',
            'renew license',
            'renewal',
            'license renewal',
            'i want to renew',
            'i want renew',
            'i need to renew',
            'i need renew',
            'renew my driving license',
            'license is expiring',
            'my license expires',
            'extend my license',
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
            // Arabic
            'ضاعت رخصتي',
            'فقدت رخصتي',
            'بدي بدل فاقد',
            'رخصتي مفقودة',
            'رخصتي ضايعة',
            'بدل ضايع',
            'بدل مفقود',
            'الرخصة ضاعت',
            'فقدان رخصة',
            // English
            'lost license replacement',
            'lost license',
            'my license is lost',
            'i lost my license',
            'i lost my driving license',
            'lost my license',
            'license lost',
            'replacement for lost',
            'replacement lost',
            'i want replacement lost',
            'i need replacement lost',
            'missing license',
            'cant find my license',
            "can't find my license",
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
            // Arabic
            'رخصتي تالفة',
            'الرخصة خربانة',
            'بدي بدل تالف',
            'الرخصة مكسورة',
            'رخصتي خربانة',
            'رخصتي مكسورة',
            'بدل خربان',
            'بدل مكسور',
            'الرخصة تالفة',
            'تلف الرخصة',
            // English
            'damaged license replacement',
            'damaged license',
            'my license is damaged',
            'i damaged my license',
            'license damaged',
            'broken license',
            'my license is broken',
            'license broken',
            'replacement for damaged',
            'replacement damaged',
            'i want replacement damaged',
            'i need replacement damaged',
            'torn license',
            'license torn',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isPaymentStatusQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'حالة الدفع', 'شو حالة الدفع', 'هل دفعت', 'دفعت الرسوم',
            'payment status', 'did i pay', 'have i paid', 'is payment done',
            'payment completed', 'check payment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isPaymentFeeQuery(string $message): bool
    {
        if (self::isPaymentStatusQuery($message)) {
            return false;
        }

        $normalized = self::normalize($message);

        foreach ([
            // Arabic
            'بدي ادفع',
            'بدي أدفع',
            'ادفع الرسوم',
            'أدفع الرسوم',
            'الدفع',
            'شو الرسوم',
            'كم الرسوم',
            'رسوم الطلب',
            'كم الرسم',
            'شو الرسم',
            // English
            'payment',
            'i want to pay',
            'i want pay',
            'i need to pay',
            'i need pay',
            'pay now',
            'application fee',
            'pay fee',
            'what is the fee',
            'how much is the fee',
            'how much fee',
            'what fee',
            'fees',
            'cost',
            'how much',
            'price',
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

        foreach ([
            // Arabic
            'بدي ادفع',
            'بدي أدفع',
            'ادفع',
            'أدفع',
            'خليني ادفع',
            'خليني أدفع',
            // English
            'pay now',
            'start payment',
            'i want to pay',
            'i want pay',
            'i need to pay',
            'i need pay',
            'let me pay',
            'make payment',
            'proceed to payment',
            'proceed payment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isRetestQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'اعادة اختبار', 'إعادة اختبار', 'إعادة الفحص', 'اعادة الفحص',
            'بدي اعيد الاختبار', 'بدي أعيد الاختبار', 'retest', 'retake test', 'retake the test',
        ] as $phrase) {
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
            // Arabic
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
            // English
            'available tests',
            'what tests are available',
            'what tests',
            'show available tests',
            'show tests',
            'current test',
            'which test should i book',
            'which test to book',
            'what test should i take',
            'what test do i need',
            'next test',
            'my test',
            'upcoming test',
            'tests i need',
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
            // Arabic
            'المواعيد المتاحة',
            'شو المواعيد',
            'اعرض المواعيد',
            'مواعيد اختبار',
            'مواعيد فحص',
            'شو في مواعيد',
            'مواعيد متاحة',
            // English
            'available slots',
            'show appointment slots',
            'show available slots',
            'appointment slots',
            'available appointments',
            'show appointments',
            'what slots',
            'what appointments',
            'free slots',
            'free appointments',
            'available times',
            'appointment times',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (AgentTestTypeExtractor::extractFromMessage($message) !== null
            && (str_contains($normalized, self::normalize('مواعيد')) 
                || str_contains($normalized, 'slot') 
                || str_contains($normalized, 'appointment'))) {
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
            // Arabic
            'احجزلي موعد',
            'احجز موعد',
            'بدي احجز موعد',
            'بدي أحجز موعد',
            'حجز موعد',
            // English
            'book appointment',
            'book an appointment',
            'i want to book appointment',
            'i want book appointment',
            'i need to book appointment',
            'i need book appointment',
            'make appointment',
            'schedule appointment',
            'reserve appointment',
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
            // Arabic
            'احجز أول موعد',
            'احجزلي الموعد الأول',
            'احجز اول موعد',
            'احجزلي اول موعد',
            'أول موعد متاح',
            'اول موعد متاح',
            'الموعد الاول',
            // English
            'book first appointment',
            'book first slot',
            'first available slot',
            'first available appointment',
            'book earliest',
            'earliest appointment',
            'earliest slot',
            'soonest appointment',
            'next available',
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
            // Arabic
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
            'مواعيدي المحجوزة',
            // English
            'did you book an appointment',
            'did you book appointment',
            'is my appointment booked',
            'show my appointment',
            'show my appointments',
            'when is my appointment',
            'my booked appointment',
            'my appointment',
            'my appointments',
            'appointment status',
            'do i have appointment',
            'do i have an appointment',
            'my current appointment',
            'booked appointments',
            'check my appointment',
            'view my appointment',
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
            || self::isBookFirstAvailableSlotQuery($message)
            || self::isCancelAppointmentQuery($message)
            || self::isRescheduleAppointmentQuery($message);
    }

    public static function isCancelAppointmentQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'الغاء الموعد', 'إلغاء الموعد', 'الغي الموعد', 'ألغي الموعد',
            'بدي الغي الموعد', 'cancel appointment', 'cancel my appointment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isRescheduleAppointmentQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            'تأجيل الموعد', 'تغيير الموعد', 'اعادة جدولة', 'إعادة جدولة',
            'بدي اغير الموعد', 'بدي أغير الموعد', 'reschedule', 'reschedule appointment',
            'change appointment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isBookAppointmentQuery(string $message): bool
    {
        if (self::isCurrentAppointmentsQuery($message)
            || self::isCancelAppointmentQuery($message)
            || self::isRescheduleAppointmentQuery($message)) {
            return false;
        }

        if (self::isVagueBookAppointmentQuery($message) || self::isBookFirstAvailableSlotQuery($message)) {
            return true;
        }

        $normalized = self::normalize($message);

        foreach ([
            // Arabic
            'احجزلي',
            'احجز موعد',
            'بدي احجز',
            'بدي أحجز',
            'حجز',
            // English
            'book appointment',
            'book an appointment',
            'i want to book',
            'i want book',
            'i need to book',
            'i need book',
            'reserve',
            'schedule',
            'make appointment',
        ] as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (AgentTestTypeExtractor::extractFromMessage($message) !== null
            && (str_contains($normalized, 'احجز') 
                || str_contains($normalized, 'book') 
                || str_contains($normalized, 'schedule')
                || str_contains($normalized, 'reserve'))) {
            return true;
        }

        return false;
    }

    public static function isTestResultsQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach ([
            // Arabic
            'نتيجة الفحص',
            'نتيجة الاختبار',
            'شو نتيجة',
            'نجحت',
            'رسبت',
            'شو نتيجتي',
            'نتائج الفحص',
            'نتائج الاختبار',
            // English
            'test result',
            'test results',
            'my test result',
            'my result',
            'exam result',
            'did i pass',
            'did i fail',
            'passed or failed',
            'pass or fail',
            'check result',
            'check my result',
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
            // Arabic
            'اعرضلي مخالفاتي',
            'مخالفاتي',
            'عندي مخالفات',
            'المخالفات',
            'مخالفات',
            'الغرامات',
            'الغرامه',
            'شو مخالفاتي',
            'غرامة',
            // English
            'fines',
            'my fines',
            'show my fines',
            'show fines',
            'violations',
            'my violations',
            'penalties',
            'my penalties',
            'do i have fines',
            'do i have any fines',
            'traffic fines',
            'traffic violations',
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
            // Arabic
            'اعرضلي رخصي',
            'رخصتي',
            'رخصي',
            'تفاصيل الرخصة',
            'شو رخصي',
            'عندي رخصة',
            'رخصي الحالية',
            // English
            'my license',
            'my licenses',
            'show my license',
            'show my licenses',
            'view my license',
            'license details',
            'my driving license',
            'current license',
            'do i have license',
            'do i have a license',
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
            // Arabic
            'حالة البروفايل',
            'حالة الملف',
            'تمت الموافقة على بروفايلي',
            'ليش ما فيني أقدم',
            'ليش ما فيني اقدم',
            'البروفايل تبعي',
            'ملفي الشخصي',
            'حسابي',
            // English
            'profile status',
            'why cant i apply',
            "why can't i apply",
            'my profile',
            'profile approved',
            'is my profile approved',
            'account status',
            'my account',
            'profile details',
            'check profile',
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

        foreach ([
            // Arabic
            'الطقس',
            'كرة القدم',
            'طبخ',
            // English
            'weather',
            'football',
            'bitcoin',
            'recipe',
            'crypto',
            'cooking',
            'sports',
            'news',
            'movie',
            'music',
        ] as $phrase) {
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
