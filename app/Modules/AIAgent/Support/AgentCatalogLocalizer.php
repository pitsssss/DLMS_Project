<?php

namespace App\Modules\AIAgent\Support;

/**
 * Locale-aware display labels for trusted catalog codes (documents, tests, services, licenses).
 * Prefer codes over raw DB `name` fields which are Arabic-seeded.
 */
final class AgentCatalogLocalizer
{
    /**
     * @var array<string, array{ar: string, en: string}>
     */
    private const DOCUMENTS = [
        'national_id_copy' => [
            'ar' => 'صورة عن الهوية الشخصية',
            'en' => 'Copy of national ID',
        ],
        'personal_photo' => [
            'ar' => 'صورة شخصية',
            'en' => 'Personal photo',
        ],
        'blood_donation_certificate' => [
            'ar' => 'شهادة تبرع بالدم',
            'en' => 'Blood donation certificate',
        ],
        'medical_report' => [
            'ar' => 'تقرير طبي',
            'en' => 'Medical report',
        ],
        'recent_personal_photo' => [
            'ar' => 'صورة شخصية حديثة',
            'en' => 'Recent personal photo',
        ],
        'medical_report_renewal' => [
            'ar' => 'تقرير طبي إن وجد ضمن متطلبات التجديد',
            'en' => 'Medical report (if required for renewal)',
        ],
        'loss_declaration' => [
            'ar' => 'تصريح فقدان أو تعهد فقدان',
            'en' => 'Loss declaration or affidavit',
        ],
        'damaged_license_proof' => [
            'ar' => 'صورة الرخصة التالفة أو إثبات التلف',
            'en' => 'Photo of damaged license or proof of damage',
        ],
    ];

    /**
     * @var array<string, array{ar: string, en: string}>
     */
    private const TEST_TYPES = [
        'vision' => [
            'ar' => 'اختبار النظر',
            'en' => 'Vision test',
        ],
        'theory' => [
            'ar' => 'الاختبار النظري',
            'en' => 'Theory test',
        ],
        'practical' => [
            'ar' => 'الاختبار العملي',
            'en' => 'Practical test',
        ],
        'specialized' => [
            'ar' => 'اختبار تخصصي',
            'en' => 'Specialized test',
        ],
    ];

    /**
     * @var array<string, array{ar: string, en: string}>
     */
    private const SERVICE_TYPES = [
        'new_license' => [
            'ar' => 'إصدار رخصة جديدة',
            'en' => 'New license',
        ],
        'renew_license' => [
            'ar' => 'تجديد رخصة',
            'en' => 'License renewal',
        ],
        'lost_replacement' => [
            'ar' => 'بدل فاقد',
            'en' => 'Lost replacement',
        ],
        'damaged_replacement' => [
            'ar' => 'بدل تالف',
            'en' => 'Damaged replacement',
        ],
        'license_unblock' => [
            'ar' => 'فك حظر رخصة',
            'en' => 'License unblock',
        ],
    ];

    public static function document(string $code, ?string $fallback = null, ?string $locale = null): string
    {
        return self::resolve(self::DOCUMENTS, $code, $fallback, $locale);
    }

    public static function testType(string $code, ?string $fallback = null, ?string $locale = null): string
    {
        return self::resolve(self::TEST_TYPES, $code, $fallback, $locale);
    }

    public static function serviceType(string $code, ?string $fallback = null, ?string $locale = null): string
    {
        return self::resolve(self::SERVICE_TYPES, $code, $fallback, $locale);
    }

    public static function licenseType(string $code, ?string $fallback = null, ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $code = trim($code);
        if ($code === '') {
            return $fallback !== null && $fallback !== '' ? $fallback : '';
        }

        $label = $locale === 'en'
            ? LicenseTypeSlotExtractor::labelEn($code)
            : LicenseTypeSlotExtractor::labelAr($code);

        if ($label !== $code) {
            return $label;
        }

        return $fallback !== null && $fallback !== '' ? $fallback : $code;
    }

    /**
     * Localize a checklist / payload document item that has code + name.
     *
     * @param  array<string, mixed>  $item
     */
    public static function documentFromItem(array $item, ?string $locale = null): string
    {
        return self::document(
            (string) ($item['code'] ?? ''),
            trim((string) ($item['name'] ?? '')) ?: null,
            $locale
        );
    }

    /**
     * Localize a test_type payload that has code + name.
     *
     * @param  array<string, mixed>|null  $testType
     */
    public static function testTypeFromPayload(?array $testType, ?string $locale = null): string
    {
        if ($testType === null) {
            return AgentTranslator::message('ai_agent.appointments.test_fallback', [], $locale);
        }

        return self::testType(
            (string) ($testType['code'] ?? ''),
            trim((string) ($testType['name'] ?? '')) ?: null,
            $locale
        );
    }

    /**
     * @param  array<string, array{ar: string, en: string}>  $map
     */
    private static function resolve(array $map, string $code, ?string $fallback, ?string $locale): string
    {
        $locale = self::normalizeLocale($locale);
        $code = trim($code);

        if ($code !== '' && isset($map[$code][$locale])) {
            return $map[$code][$locale];
        }

        if ($fallback !== null && trim($fallback) !== '') {
            return trim($fallback);
        }

        return $code;
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = $locale ?? AgentTranslator::getLocale();

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }
}
