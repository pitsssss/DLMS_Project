<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

class CitizenMessageTranslator
{
    private const LOCALE = 'ar';

    public static function get(string $key, array $replace = []): string
    {
        $fullKey = str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;

        if (Lang::has($fullKey, self::LOCALE)) {
            $translated = Lang::get($fullKey, $replace, self::LOCALE);

            if (is_string($translated) && ! self::looksLikeUnresolvedKey($translated, $fullKey)) {
                return $translated;
            }
        }

        return self::fallback($fullKey, $replace);
    }

    private static function looksLikeUnresolvedKey(string $translated, string $fullKey): bool
    {
        return $translated === $fullKey || str_starts_with($translated, 'messages.');
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function fallback(string $fullKey, array $replace): string
    {
        $suffix = str_replace('messages.', '', $fullKey);

        if (str_starts_with($suffix, 'tests.availability.')) {
            $code = substr($suffix, strlen('tests.availability.'));

            $text = match ($code) {
                'next_action_book' => 'حجز موعد',
                'previous_test_fallback' => 'الاختبار السابق',
                'application_not_ready_for_testing' => 'لا يمكن حجز الاختبارات حالياً لأن الطلب ليس في مرحلة الاختبارات.',
                'payment_not_completed' => 'لا يمكن حجز الاختبارات قبل إكمال عملية الدفع.',
                'previous_test_not_passed' => 'يجب اجتياز :previous_test أولاً قبل حجز :current_test.',
                'already_passed' => 'تم اجتياز هذا الاختبار مسبقاً.',
                'active_appointment_exists' => 'لديك موعد فعال لهذا الاختبار بالفعل.',
                'waiting_result' => 'لديك موعد سابق لهذا الاختبار بانتظار تسجيل النتيجة.',
                'max_attempts_reached' => 'تم الوصول إلى الحد الأقصى لمحاولات هذا الاختبار. سيتم تحويل الطلب للمراجعة الإدارية.',
                'not_current_test' => 'هذا الاختبار غير متاح حالياً بسبب تسلسل الاختبارات.',
                'application_already_approved' => 'تم اجتياز جميع الاختبارات وأصبح الطلب مؤهلاً لإصدار الرخصة.',
                'license_already_issued' => 'تم إصدار الرخصة لهذا الطلب مسبقاً.',
                'application_blocked_or_cancelled' => 'لا يمكن حجز اختبارات لهذا الطلب لأنه غير فعال حالياً.',
                default => 'هذا الاختبار غير متاح حالياً.',
            };

            return self::replacePlaceholders($text, $replace);
        }

        return match ($suffix) {
            'appointments.available_tests' => 'تم جلب الاختبارات المتاحة بنجاح.',
            'generic.success' => 'تمت العملية بنجاح.',
            'generic.error' => 'حدث خطأ.',
            default => 'عذراً، تعذر عرض الرسالة حالياً. يرجى المحاولة لاحقاً.',
        };
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
