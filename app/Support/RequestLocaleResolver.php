<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;

class RequestLocaleResolver
{
    /**
     * Resolve the application locale for the current HTTP request.
     *
     * Precedence:
     * 1. First supported locale from Accept-Language (including regional variants)
     * 2. Authenticated user's stored users.language when supported
     * 3. Configured default locale (ar)
     *
     * Unsupported Accept-Language values do not override a valid stored preference.
     * Accept-Language is never persisted.
     */
    public function resolve(Request $request): string
    {
        $fromHeader = $this->localeFromAcceptLanguage($request);

        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $fromUser = $this->localeFromAuthenticatedUser($request);

        if ($fromUser !== null) {
            return $fromUser;
        }

        return $this->defaultLocale();
    }

    public function defaultLocale(): string
    {
        $default = (string) config('localization.default', config('app.locale', 'ar'));

        return $this->isSupported($default) ? $default : 'ar';
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
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

    public function isSupported(?string $locale): bool
    {
        if ($locale === null || $locale === '') {
            return false;
        }

        return in_array(strtolower(trim($locale)), $this->supportedLocales(), true);
    }

    private function localeFromAcceptLanguage(Request $request): ?string
    {
        $header = $request->headers->get('Accept-Language');

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        try {
            $items = AcceptHeader::fromString($header)->all();
        } catch (\Throwable) {
            return null;
        }

        foreach ($items as $item) {
            if ($item->getQuality() <= 0.0) {
                continue;
            }

            $normalized = $this->normalizeToSupported($item->getValue());

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function localeFromAuthenticatedUser(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $language = $user->language ?? null;

        if (! is_string($language)) {
            return null;
        }

        $normalized = strtolower(trim($language));

        return $this->isSupported($normalized) ? $normalized : null;
    }

    private function normalizeToSupported(string $tag): ?string
    {
        $tag = strtolower(trim(str_replace('_', '-', $tag)));

        if ($tag === '' || $tag === '*') {
            return null;
        }

        $base = explode('-', $tag, 2)[0];

        return $this->isSupported($base) ? $base : null;
    }
}
