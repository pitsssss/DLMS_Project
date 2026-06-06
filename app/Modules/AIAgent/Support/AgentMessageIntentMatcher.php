<?php

namespace App\Modules\AIAgent\Support;

class AgentMessageIntentMatcher
{
    /** @var list<string> */
    private const STATUS_PHRASES = [
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
        'application status',
        'track my application',
        'where is my application',
    ];

    /** @var list<string> */
    private const REQUIRED_DOCUMENTS_PHRASES = [
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
        'required documents',
        'what documents are required',
        'documents needed',
        'what should i upload',
        'required files',
    ];

    /** @var list<string> */
    private const NEW_LICENSE_PHRASES = [
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
        'new license',
        'new driving license',
        'apply for license',
        'create application',
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
        'next step',
        'what next',
        'what should i do now',
        'continue application',
        'continue',
    ];

    private static function hasExplicitStatusQuestion(string $normalized): bool
    {
        $signals = [
            'حالة الطلب',
            'حالة طلبي',
            'شو حالة',
            'وين صار',
            'وين وصل',
            'تابع طلبي',
            'تابعلي طلبي',
            'متابعة الطلب',
            'متابعة طلبي',
            'application status',
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
            'وين صار',
            'وين وصل',
            'شو صار',
            'وصل الطلب',
            'status',
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
