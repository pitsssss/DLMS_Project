<?php

namespace App\Modules\AIAgent\Services;

/**
 * Request-scoped locale context for AI Agent.
 * 
 * This service is scoped to each request to prevent locale leakage
 * between users in long-running processes (Octane, Swoole, Workers).
 * 
 * NEVER use static mutable state for locale storage.
 */
final class AgentLocaleContext
{
    private const SUPPORTED_LOCALES = ['ar', 'en'];
    private const DEFAULT_LOCALE = 'ar';

    private string $locale = self::DEFAULT_LOCALE;
    private ?string $detectedLocale = null;
    private ?float $detectionConfidence = null;
    private ?string $detectionSource = null;

    /**
     * Set the current locale for this request.
     */
    public function setLocale(string $locale): void
    {
        $this->locale = in_array($locale, self::SUPPORTED_LOCALES, true)
            ? $locale
            : self::DEFAULT_LOCALE;
    }

    /**
     * Get the current locale for this request.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Check if the current locale is English.
     */
    public function isEnglish(): bool
    {
        return $this->locale === 'en';
    }

    /**
     * Check if the current locale is Arabic.
     */
    public function isArabic(): bool
    {
        return $this->locale === 'ar';
    }

    /**
     * Store language detection metadata.
     */
    public function setDetectionMetadata(
        string $detectedLocale,
        float $confidence,
        string $source
    ): void {
        $this->detectedLocale = $detectedLocale;
        $this->detectionConfidence = $confidence;
        $this->detectionSource = $source;
    }

    /**
     * Get detection metadata for logging/debugging.
     * 
     * @return array<string, mixed>
     */
    public function getDetectionMetadata(): array
    {
        return [
            'detected_locale' => $this->detectedLocale,
            'confidence' => $this->detectionConfidence,
            'source' => $this->detectionSource,
            'final_locale' => $this->locale,
        ];
    }

    /**
     * Get text direction for the current locale.
     */
    public function getTextDirection(): string
    {
        return $this->locale === 'ar' ? 'rtl' : 'ltr';
    }

    /**
     * Get all supported locales.
     * 
     * @return list<string>
     */
    public static function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Get default locale.
     */
    public static function getDefaultLocale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    /**
     * Validate if a locale is supported.
     */
    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }
}
