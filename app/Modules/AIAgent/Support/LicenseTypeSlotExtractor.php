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

    public static function extract(string $message): ?string
    {
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
}
