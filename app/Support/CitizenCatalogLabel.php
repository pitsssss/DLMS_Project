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
        return self::label('messages.catalog.license_types.'.$code, $fallback, $code);
    }

    public static function testType(string $code, ?string $fallback = null): string
    {
        return self::label('messages.catalog.test_types.'.$code, $fallback, $code);
    }

    public static function serviceType(string $code, ?string $fallback = null): string
    {
        return self::label('messages.catalog.service_types.'.$code.'.name', $fallback, $code);
    }

    public static function serviceTypeDescription(string $code, ?string $fallback = null): string
    {
        return self::label('messages.catalog.service_types.'.$code.'.description', $fallback, $code);
    }

    public static function requiredDocument(string $code, ?string $fallback = null): string
    {
        return self::label('messages.catalog.required_documents.'.$code, $fallback, $code);
    }

    public static function fee(string $code, ?string $fallback = null): string
    {
        return self::label('messages.fees.codes.'.$code, $fallback, $code);
    }

    private static function label(string $key, ?string $fallback, string $code): string
    {
        if (! Lang::has($key, 'ar') && ! Lang::has($key, 'en')) {
            return $fallback !== null && $fallback !== '' ? $fallback : $code;
        }

        return CitizenMessageTranslator::get($key);
    }
}
