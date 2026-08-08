<?php

namespace App\Modules\AIAgent\Support;

class AgentMessageIntentMatcher
{
    /** @var list<string> */
    private const STATUS_PHRASES = [
        // Arabic
        'حالة الطلب',
        'حالة طلبي',
        'حالة الطلب الخاص بي',
        'حالة طلبي',
        'الطلب الخاص بي',
        'طلبي الخاص',
        'وين صار',
        'وين وصل',
        'شو صار',
        'شو حالة',
        'تابع طلبي',
        'تابعلي طلبي',
        'متابعة الطلب',
        'متابعة طلبي',
        'طلباتي',
        'طلبي',
        'حالة طلب',
        'وين الطلب',
        'شو صار بالطلب',
        'صار في تحديث',
        // English
        'application status',
        'track my application',
        'where is my application',
        'my application',
        'my applications',
        'status of my application',
        'check application',
        'check my application',
        'view application',
        'view my application',
        'application progress',
        'track application',
    ];

    /** @var list<string> */
    private const REQUIRED_DOCUMENTS_PHRASES = [
        // Arabic
        'شو الوثائق المطلوبة',
        'ما هي الوثائق المطلوبة',
        'ما الوثائق المطلوبة',
        'ما المستندات المطلوبة',
        'شو الأوراق المطلوبة',
        'الأوراق المطلوبة',
        'الوثائق المطلوبة',
        'المستندات المطلوبة',
        'شو الملفات المطلوبة',
        'الملفات المطلوبة',
        'شو لازم ارفع',
        'شو لازم أرفع',
        'شو المطلوب من مستندات',
        'بدي أعرف الوثائق المطلوبة',
        'بدي اعرف الوثائق المطلوبة',
        'رفع الوثائق',
        'رفع المستندات',
        'الوثائق',
        'المستندات',
        'الأوراق',
        // English
        'required documents',
        'what documents are required',
        'what documents do i need',
        'what documents',
        'documents needed',
        'what should i upload',
        'what do i upload',
        'required files',
        'needed documents',
        'documents i need',
        'files needed',
        'upload documents',
        'which documents',
    ];

    /** @var list<string> */
    private const NEW_LICENSE_PHRASES = [
        // Arabic
        'رخصة جديدة',
        'رخصه جديده',
        'بدي رخصة',
        'بدي رخصه',
        'طلب جديد',
        'إنشاء طلب',
        'انشاء طلب',
        'أنشئ طلب',
        'انشئ طلب',
        'عمل طلب جديد',
        'بدي اعمل طلب',
        'بدي أعمل طلب',
        'بدي سوي طلب',
        'بدي أسوي طلب',
        // English
        'new license',
        'new driving license',
        'apply for license',
        'apply for driving license',
        'create application',
        'new application',
        'i want new license',
        'i want a new license',
        'i want driving license',
        'i want a driving license',
        'i need new license',
        'i need a new license',
        'i need driving license',
        'i need a driving license',
        'get new license',
        'get driving license',
        'apply for new license',
        'make application',
        'start application',
    ];

    /** @var list<string> */
    public const POSSESSIVE_CONTEXT_PHRASES = [
        'الطلب الخاص بي',
        'طلبي الخاص',
        'الطلب الخاص',
        'حالة الطلب الخاص بي',
        'الحساب الخاص بي',
        'الملف الخاص بي',
        'حسابي الخاص',
        'ملفي الخاص',
    ];

    public static function isApplicationStatusQuery(string $message): bool
    {
        if (self::isRequiredDocumentsQuery($message)) {
            return false;
        }

        if (self::isApplicationNextStepQuery($message)) {
            return false;
        }

        // Payment status also contains the word "status" / Arabic "حالة".
        if (AgentWorkflowPhraseMatcher::isPaymentStatusQuery($message)) {
            return false;
        }

        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        if (self::isExplicitNewLicenseRequest($normalized) && ! self::hasStatusTrackingSignal($normalized)) {
            return false;
        }

        if (self::hasStatusTrackingSignal($normalized)) {
            return true;
        }

        foreach (self::STATUS_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isRequiredDocumentsQuery(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        if (self::isExplicitNewLicenseRequest($normalized)) {
            return false;
        }

        foreach (self::REQUIRED_DOCUMENTS_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function isApplicationNextStepQuery(
        string $message,
        ?string $lastSessionIntent = null,
        ?int $lastDiscussedApplicationId = null,
    ): bool {
        if (self::isRequiredDocumentsQuery($message)) {
            return false;
        }

        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        if (self::hasExplicitStatusQuestion($normalized)) {
            return false;
        }

        foreach (self::NEXT_STEP_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (in_array($normalized, ['تابع', 'تابعلي', 'كمل', 'كمللي'], true)) {
            return self::sessionSuggestsApplicationFollowUp($lastSessionIntent, $lastDiscussedApplicationId);
        }

        return false;
    }

    /** @var list<string> */
    private const NEXT_STEP_PHRASES = [
        // Arabic
        'الخطوة القادمة',
        'الخطوة التالية',
        'شو الخطوة',
        'شو بعدين',
        'شو لازم أعمل',
        'شو لازم اعمل',
        'شو أعمل هلق',
        'شو اعمل هلق',
        'شو لازم أعمل هلق',
        'شو لازم اعمل هلق',
        'كمللي',
        'كمل الطلب',
        'كمللي الطلب',
        'بعدين',
        'بعدها',
        'ما التالي',
        'ما الخطوة التالية',
        'الخطوة اللي بعدها',
        'الخطوة اللي بعد',
        'شو الخطوة الجاية',
        // English
        'next step',
        'what next',
        'what is next',
        'what should i do now',
        'what do i do now',
        'what should i do next',
        'what do i do next',
        'continue application',
        'continue',
        'then what',
        'after that',
        'what comes next',
        'whats next',
        'following step',
    ];

    private static function hasExplicitStatusQuestion(string $normalized): bool
    {
        $signals = [
            // Arabic
            'حالة الطلب',
            'حالة طلبي',
            'شو حالة',
            'وين صار',
            'وين وصل',
            'تابع طلبي',
            'تابعلي طلبي',
            'متابعة الطلب',
            'متابعة طلبي',
            // English
            'application status',
            'status of application',
            'check status',
            'track application',
            'track my application',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, self::normalize($signal))) {
                return true;
            }
        }

        return false;
    }

    private static function sessionSuggestsApplicationFollowUp(
        ?string $lastSessionIntent,
        ?int $lastDiscussedApplicationId,
    ): bool {
        if ($lastDiscussedApplicationId !== null) {
            return true;
        }

        return in_array($lastSessionIntent, [
            'get_application_status',
            'get_application_next_step',
            'get_required_documents',
            'create_new_license_application',
        ], true);
    }

    public static function isExplicitNewLicenseRequest(string $message): bool
    {
        $normalized = self::normalize($message);

        foreach (self::NEW_LICENSE_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function blocksLicenseTypeExtraction(string $message): bool
    {
        return AgentWorkflowPhraseMatcher::blocksLicenseTypeExtraction($message);
    }

    public static function shouldExtractLicenseTypeSlot(
        string $message,
        ?string $intent,
        array $missingSlots,
    ): bool {
        if (AgentWorkflowPhraseMatcher::blocksLicenseTypeExtraction($message)) {
            return false;
        }

        if ($intent !== 'create_new_license_application') {
            return false;
        }

        if (in_array('license_type', $missingSlots, true)) {
            return true;
        }

        return LicenseTypeSlotExtractor::looksLikeExplicitLicenseTypeAnswer($message);
    }

    private static function hasStatusTrackingSignal(string $normalized): bool
    {
        if (self::hasExplicitStatusQuestion($normalized)) {
            return true;
        }

        $signals = [
            // Arabic
            'وين صار',
            'وين وصل',
            'شو صار',
            'وصل الطلب',
            // English
            'status',
            'track',
            'progress',
            'where is',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, self::normalize($signal))) {
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
