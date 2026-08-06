<?php

namespace App\Modules\AIAgent\Support;

class LicenseTypeSlotExtractor
{
    /** @var array<string, string> Longer phrases must be matched before shorter ones. */
    private const MAP = [
        'رخصة خاصة' => 'private',
        'رخصه خاصه' => 'private',
        'private license' => 'private',
        'رخصة عامة' => 'public',
        'رخصه عامه' => 'public',
        'public license' => 'public',
        'عمومية' => 'public',
        'عموميه' => 'public',
        'رخصة شاحنة' => 'truck',
        'رخصه شاحنه' => 'truck',
        'رخصة حافلة' => 'bus',
        'رخصه حافله' => 'bus',
        'شاحنة' => 'truck',
        'حافلة' => 'bus',
        'حافله' => 'bus',
        'باص' => 'bus',
        'خاصة' => 'private',
        'خاصه' => 'private',
        'عامة' => 'public',
        'عامه' => 'public',
        'private' => 'private',
        'public' => 'public',
        'truck' => 'truck',
        'bus' => 'bus',
    ];

    /** @var list<string> */
    private const EXPLICIT_ANSWER_PHRASES = [
        'رخصة خاصة',
        'رخصه خاصه',
        'رخصة عامة',
        'رخصه عامه',
        'رخصة شاحنة',
        'رخصه شاحنه',
        'رخصة حافلة',
        'رخصه حافله',
        'private',
        'public',
        'truck',
        'bus',
        'خاصة',
        'خاصه',
        'عامة',
        'عامه',
        'شاحنة',
        'حافلة',
        'حافله',
        'باص',
    ];

    public static function extract(string $message, bool $allowed = true): ?string
    {
        if (! $allowed || AgentMessageIntentMatcher::blocksLicenseTypeExtraction($message)) {
            return null;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));

        if ($normalized === '') {
            return null;
        }

        $needles = array_keys(self::MAP);
        usort($needles, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return self::MAP[$needle];
            }
        }

        return null;
    }

    public static function looksLikeExplicitLicenseTypeAnswer(string $message): bool
    {
        if (AgentMessageIntentMatcher::blocksLicenseTypeExtraction($message)) {
            return false;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));

        foreach (self::EXPLICIT_ANSWER_PHRASES as $phrase) {
            if ($normalized === $phrase || str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public static function labelAr(string $code): string
    {
        return match ($code) {
            'private' => 'خاصة',
            'public' => 'عامة',
            'truck' => 'شاحنة',
            'bus' => 'حافلة',
            default => $code,
        };
    }

    public static function labelEn(string $code): string
    {
        return match ($code) {
            'private' => 'Private',
            'public' => 'Public',
            'truck' => 'Truck',
            'bus' => 'Bus',
            default => $code,
        };
    }
}
