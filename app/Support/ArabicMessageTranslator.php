<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

final class ArabicMessageTranslator
{
    private const LOCALE = 'ar';

    public static function get(string $key, array $replace = []): string
    {
        $resolved = self::resolve($key, $replace);

        if ($resolved !== null) {
            return $resolved;
        }

        $fullKey = self::normalizeKey($key);

        Log::warning('Missing Arabic translation', ['key' => $fullKey]);

        return self::safeFallback($fullKey);
    }

    public static function resolve(string $key, array $replace = []): ?string
    {
        $fullKey = self::normalizeKey($key);

        if (! Lang::has($fullKey, self::LOCALE)) {
            return null;
        }

        $translated = Lang::get($fullKey, $replace, self::LOCALE);

        if (! is_string($translated) || self::looksLikeUnresolvedKey($translated, $fullKey)) {
            return null;
        }

        return $translated;
    }

    /**
     * Resolve a value that may be a stored Laravel translation key (historical data).
     */
    public static function resolveStoredLabel(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! self::looksLikeTranslationKey($value)) {
            return $value;
        }

        $resolved = self::resolve($value);

        return $resolved ?? self::safeFallback($value);
    }

    public static function looksLikeTranslationKey(string $value): bool
    {
        return (bool) preg_match('/^(messages|validation|enums|statuses|permissions|actions|documents|dashboard)\./', $value);
    }

    public static function looksLikeUnresolvedKey(string $translated, string $fullKey): bool
    {
        if ($translated === $fullKey) {
            return true;
        }

        return self::looksLikeTranslationKey($translated);
    }

    private static function normalizeKey(string $key): string
    {
        return str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;
    }

    private static function safeFallback(string $fullKey): string
    {
        $suffix = str_replace('messages.', '', $fullKey);
        $lastDot = strrpos($suffix, '.');

        $lastSegment = $lastDot === false ? $suffix : substr($suffix, $lastDot + 1);

        return str_replace('_', ' ', $lastSegment);
    }
}
