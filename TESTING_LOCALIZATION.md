# AI Agent Localization Testing Guide

## ✅ What Was Fixed

### Critical Architecture Changes

1. **Removed Static Mutable Locale State**
   - ❌ Before: `AgentTranslator` used static `$contextLocale`
   - ✅ After: Request-scoped `AgentLocaleContext` service
   - **Impact:** Octane-safe, no locale leakage between users

2. **Professional Language Detection**
   - ❌ Before: Simple character regex
   - ✅ After: `AgentLanguageDetector` with confidence scoring
   - **Impact:** Handles mixed messages, numeric responses, explicit requests

3. **Session Locale Memory**
   - ❌ Before: No memory
   - ✅ After: Stored in `ai_agent_sessions.context`
   - **Impact:** Consistent experience across conversation

4. **Enhanced Response Contract**
   - Added: `locale` field
   - Added: `text_direction` field
   - **Impact:** Flutter knows how to render UI

## 🧪 Running Tests

### All Tests
```bash
php artisan test
```

### AI Agent Tests Only
```bash
php artisan test --filter=AIAgent
```

### New Locale Tests
```bash
php artisan test tests/Unit/AgentLocaleContextTest.php
php artisan test tests/Unit/AgentLanguageDetectorTest.php
```

## 📝 Test Helpers

### Using AIAgentTestHelper Trait

```php
use Tests\Support\AIAgentTestHelper;

class MyTest extends TestCase
{
    use AIAgentTestHelper;

    public function test_english_response(): void
    {
        $this->setUpEnglishLocale();
        
        $response = // ... call AI agent
        
        $this->assertEnglishResponse($response);
    }
}
```

### Helper Methods

- `setUpLocaleContext(string $locale)` - Set locale for test
- `setUpEnglishLocale()` - Set English locale
- `setUpArabicLocale()` - Set Arabic locale (default)
- `assertResponseLocale(array $response, string $locale)` - Assert locale
- `assertResponseTextDirection(array $response, string $direction)` - Assert direction
- `assertArabicResponse(array $response)` - Assert Arabic with RTL
- `assertEnglishResponse(array $response)` - Assert English with LTR

## ⚠️ Common Issues

### Issue 1: Tests Fail with "AgentLocaleContext not found"

**Cause:** Service not registered in test  
**Fix:** Service is auto-registered in `AppServiceProvider`, no action needed

### Issue 2: Locale Always Returns 'ar'

**Cause:** Context not set in test  
**Fix:** Use helper methods or set context explicitly:

```php
$context = app(AgentLocaleContext::class);
$context->setLocale('en');
```

### Issue 3: Detection Tests Fail

**Cause:** Language detector expectations don't match actual behavior  
**Fix:** Check confidence thresholds and technical terms list

## 📊 Test Coverage

### Unit Tests

#### AgentLocaleContext (11 tests)
- ✅ Default locale is Arabic
- ✅ Can set English locale
- ✅ Can set Arabic locale
- ✅ Invalid locale defaults to Arabic
- ✅ Arabic has RTL direction
- ✅ English has LTR direction
- ✅ Detection metadata storage
- ✅ Supported locales validation
- ✅ Static methods work correctly

#### AgentLanguageDetector (20+ tests)
- ✅ Detects pure Arabic
- ✅ Detects pure English
- ✅ Handles mixed Arabic + technical terms
- ✅ Handles numeric-only messages
- ✅ Detects explicit Arabic requests
- ✅ Detects explicit English requests
- ✅ Detects short responses (yes/no)
- ✅ Handles empty strings
- ✅ Handles punctuation-only
- ✅ Uses session locale for ambiguous
- ✅ Technical terms don't override Arabic
- ✅ Case-insensitive detection

### Integration Tests (Existing)

All existing AI Agent tests should pass without modification because:
- Services are auto-injected via Laravel Container
- Default locale is Arabic (backward compatible)
- `AgentTranslator::getLocale()` has safe fallback

## 🔍 Debugging

### Enable Locale Detection Logging

```php
// In AIAgentService
$detection = $prepared['language_detection'];
\Log::info('Locale Detection', $detection);
```

### Check Session Locale

```php
// In test or debugging
$session = AIAgentSession::find($sessionId);
dd($session->context['locale']);
```

### Verify Context in Request

```php
// In controller or service
$context = app(AgentLocaleContext::class);
dd($context->getLocale(), $context->getDetectionMetadata());
```

## ✨ Best Practices

### 1. Always Use Scoped Context

```php
// ❌ Don't
AgentTranslator::setLocale('en');

// ✅ Do
$context = app(AgentLocaleContext::class);
$context->setLocale('en');
```

### 2. Test Both Languages

```php
public function test_action_works_in_arabic(): void { }
public function test_action_works_in_english(): void { }
```

### 3. Test Language Switching

```php
public function test_user_can_switch_language(): void
{
    // First message in English
    $response1 = $this->postJson('/api/ai-agent/message', [
        'message' => 'What is my application status?',
    ]);
    
    $this->assertEnglishResponse($response1->json('data'));
    
    // Second message in Arabic
    $response2 = $this->postJson('/api/ai-agent/message', [
        'message' => 'بدي أعرف الوثائق المطلوبة',
        'session_id' => $response1->json('data.session_id'),
    ]);
    
    $this->assertArabicResponse($response2->json('data'));
}
```

### 4. Test Ambiguous Cases

```php
public function test_numeric_response_uses_session_locale(): void
{
    // Establish English session
    $response1 = $this->postJson('/api/ai-agent/message', [
        'message' => 'I want new license',
    ]);
    
    // Numeric response should use English
    $response2 = $this->postJson('/api/ai-agent/message', [
        'message' => '1',
        'session_id' => $response1->json('data.session_id'),
    ]);
    
    $this->assertEnglishResponse($response2->json('data'));
}
```

## 📚 Additional Resources

- `AgentLocaleContext.php` - Request-scoped locale service
- `AgentLanguageDetector.php` - Language detection engine
- `AgentSessionLocaleManager.php` - Session locale storage
- `AIAgentTestHelper.php` - Test helpers
- `DLMS_AI_AGENT_CONTEXT_COMPACT.md` - Full AI Agent documentation

## 🆘 Support

If tests are failing:

1. Clear all caches: `php artisan optimize:clear`
2. Check database connection
3. Run migrations: `php artisan migrate:fresh --seed`
4. Check logs: `storage/logs/laravel.log`
5. Verify services are registered: `php artisan tinker` → `app(AgentLocaleContext::class)`

## 🎯 Next Steps

After all tests pass:

1. ✅ Run full test suite
2. ✅ Test manually via Postman
3. ✅ Update Postman collection with bilingual scenarios
4. ✅ Test with Flutter app
5. ✅ Deploy to staging
6. ✅ Monitor production logs
