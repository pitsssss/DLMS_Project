<?php

namespace App\Support;

use App\Models\Faq;
use Illuminate\Support\Facades\Lang;

/**
 * Locale-aware static citizen content (FAQ, privacy, contact, themes).
 * Does not mutate app()->getLocale().
 */
final class CitizenContentLocalizer
{
    /**
     * Seeded FAQ sort_order → stable translation key (FaqSeeder order).
     *
     * @var array<int, string>
     */
    private const FAQ_BY_SORT_ORDER = [
        1 => 'profile_why_complete',
        2 => 'profile_pending_meaning',
        3 => 'profile_rejected_what_to_do',
        4 => 'how_new_license',
        5 => 'how_renew',
        6 => 'how_lost_or_damaged',
        7 => 'how_know_required_docs',
        8 => 'what_if_document_rejected',
        9 => 'how_pay_fees',
        10 => 'when_book_test',
        11 => 'theory_before_vision',
        12 => 'what_if_test_failed',
        13 => 'where_view_licenses',
        14 => 'where_view_fines',
        15 => 'can_use_ai_agent',
    ];

    /**
     * Seeded Arabic category labels → stable category keys.
     *
     * @var array<string, string>
     */
    private const FAQ_CATEGORY_BY_AR = [
        'الحساب والملف الشخصي' => 'account_profile',
        'الطلبات والخدمات' => 'applications_services',
        'الوثائق والدفع' => 'documents_payment',
        'المواعيد والاختبارات' => 'appointments_tests',
        'الرخص والمخالفات' => 'licenses_fines',
    ];

    /**
     * Privacy section index (0-based, config order) → stable key.
     *
     * @var list<string>
     */
    private const PRIVACY_SECTION_KEYS = [
        'intro',
        'data_collected',
        'data_usage',
        'data_sharing',
        'data_protection',
        'uploaded_documents',
        'payments',
        'user_rights',
        'policy_updates',
        'contact',
    ];

    /**
     * @return array{category: string, question: string, answer: string}
     */
    public static function faq(Faq $faq): array
    {
        $key = self::FAQ_BY_SORT_ORDER[(int) $faq->sort_order] ?? null;

        if ($key === null) {
            return [
                'category' => (string) $faq->category,
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
            ];
        }

        $categoryKey = self::FAQ_CATEGORY_BY_AR[(string) $faq->category] ?? null;
        $category = $categoryKey !== null
            ? self::get('messages.content.faq.categories.'.$categoryKey, (string) $faq->category)
            : (string) $faq->category;

        return [
            'category' => $category,
            'question' => self::get('messages.content.faq.items.'.$key.'.question', (string) $faq->question),
            'answer' => self::get('messages.content.faq.items.'.$key.'.answer', (string) $faq->answer),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{title: string, last_updated: mixed, sections: list<array{heading: string, content: string}>}
     */
    public static function privacyPolicy(array $config): array
    {
        $sectionsConfig = is_array($config['sections'] ?? null) ? $config['sections'] : [];
        $sections = [];

        foreach (array_values($sectionsConfig) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = self::PRIVACY_SECTION_KEYS[$index] ?? null;
            $headingFallback = (string) ($section['heading'] ?? '');
            $contentFallback = (string) ($section['content'] ?? '');

            $sections[] = [
                'heading' => $sectionKey !== null
                    ? self::get('messages.content.privacy.sections.'.$sectionKey.'.heading', $headingFallback)
                    : $headingFallback,
                'content' => $sectionKey !== null
                    ? self::get('messages.content.privacy.sections.'.$sectionKey.'.content', $contentFallback)
                    : $contentFallback,
            ];
        }

        return [
            'title' => self::get('messages.content.privacy.title', (string) ($config['title'] ?? '')),
            'last_updated' => $config['last_updated'] ?? null,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function contactInfo(array $config): array
    {
        $channelsConfig = is_array($config['channels'] ?? null) ? $config['channels'] : [];
        $channels = [];

        foreach ($channelsConfig as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $type = (string) ($channel['type'] ?? '');
            $labelFallback = (string) ($channel['label'] ?? '');

            $channels[] = [
                'type' => $type,
                'label' => $type !== ''
                    ? self::get('messages.content.contact.channels.'.$type, $labelFallback)
                    : $labelFallback,
                'value' => $channel['value'] ?? null,
            ];
        }

        return [
            'title' => self::get('messages.content.contact.title', (string) ($config['title'] ?? '')),
            'description' => self::get('messages.content.contact.description', (string) ($config['description'] ?? '')),
            'phone' => $config['phone'] ?? null,
            'email' => $config['email'] ?? null,
            'working_hours' => self::get(
                'messages.content.contact.working_hours',
                (string) ($config['working_hours'] ?? '')
            ),
            'address' => $config['address'] ?? null,
            'channels' => $channels,
        ];
    }

    public static function theme(string $code, ?string $fallback = null): string
    {
        return self::get('messages.settings.themes.'.$code, $fallback !== null && $fallback !== '' ? $fallback : $code);
    }

    /**
     * @param  list<array{code?: string, name?: string}>  $themes
     * @return list<array{code: string, name: string}>
     */
    public static function themes(array $themes): array
    {
        $localized = [];

        foreach ($themes as $theme) {
            if (! is_array($theme)) {
                continue;
            }

            $code = (string) ($theme['code'] ?? '');
            $localized[] = [
                'code' => $code,
                'name' => self::theme($code, (string) ($theme['name'] ?? $code)),
            ];
        }

        return $localized;
    }

    private static function get(string $key, string $fallback): string
    {
        if (! Lang::has($key, 'ar') && ! Lang::has($key, 'en')) {
            return $fallback;
        }

        return CitizenMessageTranslator::get($key);
    }
}
