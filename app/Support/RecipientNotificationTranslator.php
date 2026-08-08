<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Lang;

/**
 * Explicit-locale translation for notification title/body creation.
 * Does not mutate app()->getLocale().
 */
final class RecipientNotificationTranslator
{
    public static function localeForUser(?User $user): string
    {
        $language = is_string($user?->language ?? null)
            ? strtolower(trim((string) $user->language))
            : '';

        if ($language !== '' && self::isSupported($language)) {
            return $language;
        }

        return self::defaultLocale();
    }

    public static function localeForUserId(int $userId): string
    {
        $language = User::query()->whereKey($userId)->value('language');

        if (is_string($language) && self::isSupported(strtolower(trim($language)))) {
            return strtolower(trim($language));
        }

        return self::defaultLocale();
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function getForUserId(int $userId, string $key, array $replace = []): string
    {
        return self::get($key, $replace, self::localeForUserId($userId));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function getForUser(?User $user, string $key, array $replace = []): string
    {
        return self::get($key, $replace, self::localeForUser($user));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $fullKey = str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;
        $locale = self::normalizeLocale($locale);
        $defaultLocale = self::defaultLocale();

        $translated = self::translate($fullKey, $replace, $locale);

        if ($translated !== null) {
            return $translated;
        }

        if ($locale !== $defaultLocale) {
            $translated = self::translate($fullKey, $replace, $defaultLocale);

            if ($translated !== null) {
                return $translated;
            }
        }

        return self::replacePlaceholders($fullKey, $replace);
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return self::isSupported($locale) ? $locale : self::defaultLocale();
    }

    private static function defaultLocale(): string
    {
        $default = strtolower(trim((string) config('localization.default', 'ar')));

        return self::isSupported($default) ? $default : 'ar';
    }

    private static function isSupported(string $locale): bool
    {
        return in_array($locale, self::supportedLocales(), true);
    }

    /**
     * @return list<string>
     */
    private static function supportedLocales(): array
    {
        $supported = config('localization.supported', ['ar', 'en']);

        if (! is_array($supported) || $supported === []) {
            return ['ar', 'en'];
        }

        return array_values(array_filter(
            array_map(static fn ($locale) => is_string($locale) ? strtolower(trim($locale)) : '', $supported),
            static fn (string $locale) => $locale !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function translate(string $fullKey, array $replace, string $locale): ?string
    {
        if (! Lang::has($fullKey, $locale)) {
            return null;
        }

        $translated = Lang::get($fullKey, $replace, $locale);

        if (! is_string($translated) || $translated === $fullKey || str_starts_with($translated, 'messages.')) {
            return null;
        }

        return $translated;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function replacePlaceholders(string $text, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }
}
