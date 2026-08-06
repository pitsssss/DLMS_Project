<?php

namespace Tests\Unit;

use App\Modules\AIAgent\Services\AgentLocaleContext;
use Tests\TestCase;

class AgentLocaleContextTest extends TestCase
{
    public function test_default_locale_is_arabic(): void
    {
        $context = new AgentLocaleContext();
        
        $this->assertEquals('ar', $context->getLocale());
        $this->assertTrue($context->isArabic());
        $this->assertFalse($context->isEnglish());
    }

    public function test_can_set_english_locale(): void
    {
        $context = new AgentLocaleContext();
        $context->setLocale('en');
        
        $this->assertEquals('en', $context->getLocale());
        $this->assertTrue($context->isEnglish());
        $this->assertFalse($context->isArabic());
    }

    public function test_can_set_arabic_locale(): void
    {
        $context = new AgentLocaleContext();
        $context->setLocale('ar');
        
        $this->assertEquals('ar', $context->getLocale());
        $this->assertTrue($context->isArabic());
        $this->assertFalse($context->isEnglish());
    }

    public function test_invalid_locale_defaults_to_arabic(): void
    {
        $context = new AgentLocaleContext();
        $context->setLocale('fr'); // Not supported
        
        $this->assertEquals('ar', $context->getLocale());
    }

    public function test_arabic_has_rtl_direction(): void
    {
        $context = new AgentLocaleContext();
        $context->setLocale('ar');
        
        $this->assertEquals('rtl', $context->getTextDirection());
    }

    public function test_english_has_ltr_direction(): void
    {
        $context = new AgentLocaleContext();
        $context->setLocale('en');
        
        $this->assertEquals('ltr', $context->getTextDirection());
    }

    public function test_detection_metadata_can_be_stored(): void
    {
        $context = new AgentLocaleContext();
        $context->setDetectionMetadata('en', 0.85, 'dominant_script');
        
        $metadata = $context->getDetectionMetadata();
        
        $this->assertEquals('en', $metadata['detected_locale']);
        $this->assertEquals(0.85, $metadata['confidence']);
        $this->assertEquals('dominant_script', $metadata['source']);
    }

    public function test_supported_locales_contains_arabic_and_english(): void
    {
        $supported = AgentLocaleContext::getSupportedLocales();
        
        $this->assertContains('ar', $supported);
        $this->assertContains('en', $supported);
        $this->assertCount(2, $supported);
    }

    public function test_default_locale_is_arabic_static(): void
    {
        $this->assertEquals('ar', AgentLocaleContext::getDefaultLocale());
    }

    public function test_is_supported_validates_correctly(): void
    {
        $this->assertTrue(AgentLocaleContext::isSupported('ar'));
        $this->assertTrue(AgentLocaleContext::isSupported('en'));
        $this->assertFalse(AgentLocaleContext::isSupported('fr'));
        $this->assertFalse(AgentLocaleContext::isSupported('es'));
    }
}
