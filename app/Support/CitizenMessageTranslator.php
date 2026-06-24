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

        if (str_starts_with($suffix, 'appointments.')) {
            $code = substr($suffix, strlen('appointments.'));

            $text = match ($code) {
                'available_tests' => 'تم جلب الاختبارات المتاحة بنجاح.',
                'slots' => 'تم جلب المواعيد المتاحة بنجاح.',
                'booked' => 'تم حجز موعد الاختبار بنجاح.',
                'rescheduled' => 'تم تعديل موعد الاختبار بنجاح.',
                'cancelled' => 'تم إلغاء موعد الاختبار بنجاح.',
                'list' => 'تم جلب مواعيد الاختبارات بنجاح.',
                'test_type_not_found' => 'نوع الاختبار غير موجود.',
                'no_test_available' => 'لا يمكن حجز هذا الاختبار حالياً.',
                'previous_test_not_passed' => 'لا يمكن حجز هذا الاختبار حالياً. يجب اجتياز :previous_test أولاً قبل حجز :current_test.',
                'slot_unavailable' => 'موعد الاختبار المحدد غير متاح.',
                'not_found' => 'موعد الاختبار غير موجود.',
                'only_booked_reschedule' => 'يمكن تعديل المواعيد المحجوزة فقط.',
                'only_booked_cancel' => 'يمكن إلغاء المواعيد المحجوزة فقط.',
                'slot_not_available' => 'الموعد المحدد غير متاح.',
                'cannot_book_status' => 'لا يمكن حجز المواعيد في الحالة الحالية للطلب.',
                'test_not_ready' => 'لا يمكن حجز هذا الاختبار حالياً. أكمل الاختبارات السابقة أو أنهِ الحجز الحالي أولاً.',
                'prior_tests_required' => 'يجب اجتياز الاختبارات السابقة قبل حجز هذا الاختبار.',
                'note_booked' => 'تم حجز موعد الاختبار. الطلب الآن قيد الاختبار.',
                default => 'لا يمكن حجز هذا الاختبار حالياً.',
            };

            return self::replacePlaceholders($text, $replace);
        }

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
            'ai_agent.response_generated' => 'تم إنشاء رد المساعد الذكي بنجاح.',
            'ai_agent.sessions_list' => 'تم جلب جلسات المساعد الذكي بنجاح.',
            'ai_agent.session_retrieved' => 'تم جلب جلسة المساعد الذكي بنجاح.',
            'ai_agent.action_executed' => 'تم تنفيذ عملية المساعد الذكي بنجاح.',
            'ai_agent.action_cancelled' => 'تم إلغاء عملية المساعد الذكي بنجاح.',
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
