<?php

namespace Tests\Unit;

use App\Modules\AIAgent\Services\AgentLanguageDetector;
use Tests\TestCase;

class AgentLanguageDetectorTest extends TestCase
{
    private AgentLanguageDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AgentLanguageDetector();
    }

    public function test_detects_pure_arabic(): void
    {
        $result = $this->detector->detect('بدي رخصة جديدة');
        
        $this->assertEquals('ar', $result['locale']);
        $this->assertGreaterThanOrEqual(0.7, $result['confidence']);
        $this->assertEquals('dominant_script', $result['source']);
        $this->assertFalse($result['is_explicit']);
    }

    public function test_detects_pure_english(): void
    {
        $result = $this->detector->detect('I want a new driving license');
        
        $this->assertEquals('en', $result['locale']);
        $this->assertGreaterThanOrEqual(0.7, $result['confidence']);
        $this->assertEquals('dominant_script', $result['source']);
        $this->assertFalse($result['is_explicit']);
    }

    public function test_handles_mixed_arabic_with_technical_term(): void
    {
        $result = $this->detector->detect('شو status طلبي');
        
        // Should detect as Arabic despite "status"
        $this->assertEquals('ar', $result['locale']);
    }

    public function test_handles_numeric_only_with_session_fallback(): void
    {
        $result = $this->detector->detect('25', 'en');
        
        $this->assertEquals('en', $result['locale']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertStringContainsString('fallback', $result['source']);
    }

    public function test_handles_numeric_only_without_session(): void
    {
        $result = $this->detector->detect('1');
        
        // Should fallback to Arabic default
        $this->assertEquals('ar', $result['locale']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function test_detects_explicit_arabic_request(): void
    {
        $result = $this->detector->detect('احكي عربي');
        
        $this->assertEquals('ar', $result['locale']);
        $this->assertEquals(1.0, $result['confidence']);
        $this->assertEquals('explicit_request', $result['source']);
        $this->assertTrue($result['is_explicit']);
    }

    public function test_detects_explicit_english_request(): void
    {
        $result = $this->detector->detect('speak english');
        
        $this->assertEquals('en', $result['locale']);
        $this->assertEquals(1.0, $result['confidence']);
        $this->assertEquals('explicit_request', $result['source']);
        $this->assertTrue($result['is_explicit']);
    }

    public function test_detects_arabic_short_response(): void
    {
        $result = $this->detector->detect('نعم');
        
        $this->assertEquals('ar', $result['locale']);
        $this->assertEquals(0.95, $result['confidence']);
        $this->assertEquals('short_response', $result['source']);
    }

    public function test_detects_english_short_response(): void
    {
        $result = $this->detector->detect('yes');
        
        $this->assertEquals('en', $result['locale']);
        $this->assertEquals(0.95, $result['confidence']);
        $this->assertEquals('short_response', $result['source']);
    }

    public function test_short_response_inherits_session_locale(): void
    {
        $enYes = $this->detector->detect('yes', 'ar');
        $this->assertEquals('ar', $enYes['locale']);
        $this->assertEquals('short_response_inherit_session', $enYes['source']);
        $this->assertLessThan(0.7, $enYes['confidence']);

        $arNo = $this->detector->detect('لا', 'en');
        $this->assertEquals('en', $arNo['locale']);
        $this->assertEquals('short_response_inherit_session', $arNo['source']);
    }

    public function test_handles_empty_string(): void
    {
        $result = $this->detector->detect('');
        
        $this->assertEquals('ar', $result['locale']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function test_handles_punctuation_only(): void
    {
        $result = $this->detector->detect('???');
        
        $this->assertEquals('ar', $result['locale']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function test_uses_session_locale_for_ambiguous(): void
    {
        $result = $this->detector->detect('...', 'en');
        
        $this->assertEquals('en', $result['locale']);
    }

    public function test_technical_terms_dont_override_arabic(): void
    {
        $messages = [
            'بدي أعرف payment تبع الطلب',
            'شو application status',
            'رخصتي pdf جاهزة',
        ];

        foreach ($messages as $message) {
            $result = $this->detector->detect($message);
            $this->assertEquals('ar', $result['locale'], "Failed for: {$message}");
        }
    }

    public function test_english_dominant_mixed_message(): void
    {
        $result = $this->detector->detect('What is وين my application');
        
        // English should dominate
        $this->assertEquals('en', $result['locale']);
    }

    public function test_explicit_request_in_mixed_context(): void
    {
        $result = $this->detector->detect('I want status but تكلم عربي please');
        
        // Explicit Arabic request should win
        $this->assertEquals('ar', $result['locale']);
        $this->assertTrue($result['is_explicit']);
    }

    public function test_case_insensitive_detection(): void
    {
        $result1 = $this->detector->detect('YES');
        $result2 = $this->detector->detect('yes');
        $result3 = $this->detector->detect('Yes');
        
        $this->assertEquals('en', $result1['locale']);
        $this->assertEquals('en', $result2['locale']);
        $this->assertEquals('en', $result3['locale']);
    }
}
