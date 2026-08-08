<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentSession;

/**
 * Ensures every citizen-facing Agent response carries stable language metadata.
 */
class AgentResponseLocale
{
    public function __construct(
        private readonly AgentLocaleContext $localeContext,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function decorate(array $payload): array
    {
        $locale = $this->localeContext->getLocale();
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = AgentLocaleContext::getDefaultLocale();
        }

        $payload['language'] = $locale;
        $payload['locale'] = $locale;
        $payload['text_direction'] = $locale === 'ar' ? 'rtl' : 'ltr';

        return $payload;
    }

    public function applySessionLocale(AIAgentSession $session): string
    {
        $manager = app(AgentSessionLocaleManager::class);
        $locale = $manager->getSessionLocale($session) ?? AgentLocaleContext::getDefaultLocale();
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = AgentLocaleContext::getDefaultLocale();
        }

        $this->localeContext->setLocale($locale);
        app()->setLocale($locale);

        return $locale;
    }
}
