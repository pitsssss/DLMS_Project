<?php

namespace Tests\Support;

use App\Modules\AIAgent\Services\AgentLocaleContext;

/**
 * Helper trait for AI Agent tests.
 */
trait AIAgentTestHelper
{
    /**
     * Set up locale context for testing.
     */
    protected function setUpLocaleContext(string $locale = 'ar'): AgentLocaleContext
    {
        /** @var AgentLocaleContext $context */
        $context = app(AgentLocaleContext::class);
        $context->setLocale($locale);
        
        return $context;
    }

    /**
     * Set up English locale for testing.
     */
    protected function setUpEnglishLocale(): AgentLocaleContext
    {
        return $this->setUpLocaleContext('en');
    }

    /**
     * Set up Arabic locale for testing.
     */
    protected function setUpArabicLocale(): AgentLocaleContext
    {
        return $this->setUpLocaleContext('ar');
    }

    /**
     * Assert response has correct locale.
     */
    protected function assertResponseLocale(array $response, string $expectedLocale): void
    {
        $this->assertArrayHasKey('locale', $response);
        $this->assertEquals($expectedLocale, $response['locale']);
    }

    /**
     * Assert response has correct text direction.
     */
    protected function assertResponseTextDirection(array $response, string $expectedDirection): void
    {
        $this->assertArrayHasKey('text_direction', $response);
        $this->assertEquals($expectedDirection, $response['text_direction']);
    }

    /**
     * Assert Arabic response.
     */
    protected function assertArabicResponse(array $response): void
    {
        $this->assertResponseLocale($response, 'ar');
        $this->assertResponseTextDirection($response, 'rtl');
    }

    /**
     * Assert English response.
     */
    protected function assertEnglishResponse(array $response): void
    {
        $this->assertResponseLocale($response, 'en');
        $this->assertResponseTextDirection($response, 'ltr');
    }
}
