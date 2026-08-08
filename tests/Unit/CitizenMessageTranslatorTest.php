<?php

namespace Tests\Unit;

use App\Support\ArabicMessageTranslator;
use App\Support\CitizenMessageTranslator;
use App\Support\Msg;
use Tests\TestCase;

class CitizenMessageTranslatorTest extends TestCase
{
    public function test_arabic_locale_returns_arabic_message(): void
    {
        app()->setLocale('ar');

        $this->assertSame(
            'تمت العملية بنجاح.',
            CitizenMessageTranslator::get('messages.generic.success')
        );
    }

    public function test_english_locale_returns_english_when_en_pack_exists(): void
    {
        app()->setLocale('en');

        $message = CitizenMessageTranslator::get('messages.generic.success');

        $this->assertSame('Operation completed successfully.', $message);
        $this->assertStringNotContainsString('messages.', $message);
    }

    public function test_missing_english_key_falls_back_to_arabic_without_exposing_key(): void
    {
        app()->setLocale('en');

        // Intentionally absent from EN pack (dashboard-only / out of Phase 2.3 scope).
        $message = CitizenMessageTranslator::get('messages.dashboard.overview_other');

        $this->assertSame('أخرى', $message);
        $this->assertStringNotContainsString('messages.', $message);
    }

    public function test_replacements_still_work_in_english(): void
    {
        app()->setLocale('en');

        $message = CitizenMessageTranslator::get('messages.tests.availability.previous_test_not_passed', [
            'previous_test' => 'Vision Test',
            'current_test' => 'Theory Test',
        ]);

        $this->assertSame(
            'You must pass Vision Test before booking Theory Test.',
            $message
        );
        $this->assertStringNotContainsString(':previous_test', $message);
        $this->assertStringNotContainsString('messages.', $message);
    }

    public function test_unsupported_locale_falls_back_to_arabic(): void
    {
        app()->setLocale('fr');

        $this->assertSame(
            'تمت العملية بنجاح.',
            CitizenMessageTranslator::get('messages.generic.success')
        );
    }

    public function test_arabic_message_translator_and_msg_remain_forced_arabic(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            'تمت العملية بنجاح.',
            ArabicMessageTranslator::get('messages.generic.success')
        );
        $this->assertSame(
            'تمت العملية بنجاح.',
            Msg::get('generic.success')
        );
    }
}
