<?php

namespace App\Modules\AIAgent\Services;

/**
 * Professional language detector for AI Agent messages.
 * 
 * Handles:
 * - Pure Arabic messages
 * - Pure English messages
 * - Mixed messages (Arabic + technical English terms)
 * - Numeric-only messages
 * - Short responses (yes/no/ok)
 * - Explicit language requests
 */
class AgentLanguageDetector
{
    /**
     * Technical terms that should not trigger language switch when in Arabic context.
     * 
     * @var list<string>
     */
    private const TECHNICAL_TERMS = [
        'status', 'id', 'payment', 'application', 'pdf', 'png', 'jpg', 'jpeg',
        'otp', 'api', 'ok', 'url', 'sms', 'app', 'online', 'email',
    ];

    /**
     * Explicit Arabic requests.
     * 
     * @var list<string>
     */
    private const EXPLICIT_ARABIC_REQUESTS = [
        'رد علي بالعربي',
        'رد بالعربي',
        'احكي عربي',
        'تكلم عربي',
        'تكلم بالعربية',
        'اتكلم عربي',
        'بدي عربي',
        'استخدم العربية',
    ];

    /**
     * Explicit English requests.
     * 
     * @var list<string>
     */
    private const EXPLICIT_ENGLISH_REQUESTS = [
        'answer in english',
        'speak english',
        'reply in english',
        'use english',
        'english please',
        'switch to english',
        'i want english',
    ];

    /**
     * Arabic short responses.
     * 
     * @var list<string>
     */
    private const ARABIC_SHORT_RESPONSES = [
        'نعم', 'لا', 'اه', 'اي', 'ايوة', 'موافق', 'تمام', 'ماشي', 
        'الغي', 'ألغي', 'راجع', 'كمل', 'خليني', 'بعدين',
    ];

    /**
     * English short responses.
     * 
     * @var list<string>
     */
    private const ENGLISH_SHORT_RESPONSES = [
        'yes', 'no', 'ok', 'okay', 'sure', 'fine', 'good', 'cancel',
        'back', 'next', 'continue', 'skip', 'first', 'second', 'third',
    ];

    /**
     * Detect language from message.
     * 
     * @return array{
     *   locale: string,
     *   confidence: float,
     *   source: string,
     *   is_explicit: bool
     * }
     */
    public function detect(string $message, ?string $sessionLocale = null): array
    {
        $normalized = $this->normalize($message);

        // 1. Check for explicit language requests (highest priority)
        $explicit = $this->detectExplicitRequest($normalized);
        if ($explicit !== null) {
            return [
                'locale' => $explicit,
                'confidence' => 1.0,
                'source' => 'explicit_request',
                'is_explicit' => true,
            ];
        }

        // 2. Check for short responses with clear language
        $shortResponse = $this->detectShortResponse($normalized);
        if ($shortResponse !== null) {
            return [
                'locale' => $shortResponse,
                'confidence' => 0.95,
                'source' => 'short_response',
                'is_explicit' => false,
            ];
        }

        // 3. Check if numeric/punctuation only (ambiguous)
        if ($this->isAmbiguous($normalized)) {
            return [
                'locale' => $sessionLocale ?? AgentLocaleContext::getDefaultLocale(),
                'confidence' => 0.0,
                'source' => 'ambiguous_fallback_to_session',
                'is_explicit' => false,
            ];
        }

        // 4. Count Arabic and English characters
        $counts = $this->countCharacters($message);

        if ($this->hasArabicContextAnchor($message) && $this->countEnglishOnlyTokens($message) <= 2) {
            return [
                'locale' => 'ar',
                'confidence' => 0.8,
                'source' => 'dominant_script',
                'is_explicit' => false,
            ];
        }

        // 5. Remove technical terms from English count if context is mixed
        $adjustedEnglishCount = $this->adjustForTechnicalTerms(
            $message,
            $counts['english'],
            $counts['arabic']
        );

        // 6. Calculate dominance
        $total = $counts['arabic'] + $adjustedEnglishCount;

        if ($total === 0) {
            return [
                'locale' => $sessionLocale ?? AgentLocaleContext::getDefaultLocale(),
                'confidence' => 0.0,
                'source' => 'no_letters_fallback_to_session',
                'is_explicit' => false,
            ];
        }

        $arabicRatio = $counts['arabic'] / $total;
        $englishRatio = $adjustedEnglishCount / $total;

        // 7. Determine dominant language
        if ($arabicRatio >= 0.7) {
            return [
                'locale' => 'ar',
                'confidence' => $arabicRatio,
                'source' => 'dominant_script',
                'is_explicit' => false,
            ];
        }

        if ($englishRatio >= 0.7) {
            return [
                'locale' => 'en',
                'confidence' => $englishRatio,
                'source' => 'dominant_script',
                'is_explicit' => false,
            ];
        }

        // 8. Close match or ambiguous - use session locale
        return [
            'locale' => $sessionLocale ?? AgentLocaleContext::getDefaultLocale(),
            'confidence' => max($arabicRatio, $englishRatio),
            'source' => 'ambiguous_fallback_to_session',
            'is_explicit' => false,
        ];
    }

    /**
     * Normalize message for detection.
     */
    private function normalize(string $message): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
    }

    /**
     * Detect explicit language request.
     */
    private function detectExplicitRequest(string $normalized): ?string
    {
        foreach (self::EXPLICIT_ARABIC_REQUESTS as $phrase) {
            if (str_contains($normalized, mb_strtolower($phrase))) {
                return 'ar';
            }
        }

        foreach (self::EXPLICIT_ENGLISH_REQUESTS as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'en';
            }
        }

        return null;
    }

    /**
     * Detect short response language.
     */
    private function detectShortResponse(string $normalized): ?string
    {
        // Check if it's a pure short response (no other words)
        $words = preg_split('/\s+/u', $normalized);
        if (count($words) > 2) {
            return null; // Not a short response
        }

        foreach (self::ARABIC_SHORT_RESPONSES as $response) {
            if ($normalized === mb_strtolower($response)) {
                return 'ar';
            }
        }

        foreach (self::ENGLISH_SHORT_RESPONSES as $response) {
            if ($normalized === $response) {
                return 'en';
            }
        }

        return null;
    }

    /**
     * Check if message is ambiguous (only numbers/punctuation).
     */
    private function isAmbiguous(string $message): bool
    {
        // Remove numbers, punctuation, spaces
        $clean = preg_replace('/[\d\s\p{P}]/u', '', $message);
        return $clean === '';
    }

    /**
     * Count Arabic and English characters.
     * 
     * @return array{arabic: int, english: int}
     */
    private function countCharacters(string $message): array
    {
        $arabicCount = 0;
        $englishCount = 0;

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return ['arabic' => 0, 'english' => 0];
        }

        foreach ($tokens as $token) {
            $normalizedToken = $this->normalize($token);
            $containsArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $token) === 1;
            $containsEnglish = preg_match('/[a-z]/', $normalizedToken) === 1;

            if ($containsArabic && ! $containsEnglish) {
                $arabicCount += mb_strlen($token);
                continue;
            }

            if ($containsEnglish && ! $containsArabic) {
                $englishCount += mb_strlen($token);
                continue;
            }

            if ($containsArabic && $containsEnglish) {
                $arabicCount += 1;
                $englishCount += 1;
            }
        }

        return [
            'arabic' => $arabicCount,
            'english' => $englishCount,
        ];
    }

    /**
     * Adjust English count by removing technical terms if Arabic is present.
     */
    private function adjustForTechnicalTerms(
        string $message,
        int $englishCount,
        int $arabicCount
    ): int {
        // Only adjust if there's Arabic content
        if ($arabicCount === 0) {
            return $englishCount;
        }

        $normalized = $this->normalize($message);
        $technicalTermsFound = 0;

        foreach (self::TECHNICAL_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                $technicalTermsFound++;
            }
        }

        if ($arabicCount > 0 && $englishCount > 0 && $arabicCount >= $englishCount) {
            return max(0, $englishCount - $technicalTermsFound);
        }

        // Reduce the impact of isolated technical terms so English-dominant
        // messages still resolve to English, while Arabic-heavy mixed messages
        // continue to prefer Arabic.
        return max(0, $englishCount - $technicalTermsFound);
    }

    private function hasArabicContextAnchor(string $message): bool
    {
        $normalized = $this->normalize($message);

        return str_contains($normalized, 'شو')
            || str_contains($normalized, 'بدي')
            || str_contains($normalized, 'أعرف')
            || str_contains($normalized, 'تبع')
            || str_contains($normalized, 'رخصتي')
            || str_contains($normalized, 'جاهزة')
            || str_contains($normalized, 'وين');
    }

    private function countEnglishOnlyTokens(string $message): int
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return 0;
        }

        $count = 0;
        foreach ($tokens as $token) {
            $containsArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $token) === 1;
            $containsEnglish = preg_match('/[a-z]/', $this->normalize($token)) === 1;

            if ($containsEnglish && ! $containsArabic) {
                $count++;
            }
        }

        return $count;
    }
}
