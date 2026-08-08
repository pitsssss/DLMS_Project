<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Locale-aware catalog labels for stable citizen codes (not DB free-text).
 */
final class CitizenCatalogLabel
{
    public static function licenseType(string $code, ?string $fallback = null): string
    {
        return self::label('license_types', $code, $fallback);
    }

    public static function testType(string $code, ?string $fallback = null): string
    {
        return self::label('test_types', $code, $fallback);
    }

    private static function label(string $group, string $code, ?string $fallback = null): string
    {
        $key = 'messages.catalog.'.$group.'.'.$code;

        if (! Lang::has($key, 'ar') && ! Lang::has($key, 'en')) {
            return $fallback !== null && $fallback !== '' ? $fallback : $code;
        }

        return CitizenMessageTranslator::get($key);
    }
}
