<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

class EmployeeMessageTranslator
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

    private static function fallback(string $fullKey, array $replace): string
    {
        $suffix = str_replace('messages.', '', $fullKey);

        if (str_starts_with($suffix, 'employee.statuses.')) {
            $code = substr($suffix, strlen('employee.statuses.'));
            return match ($code) {
                'draft'                  => 'مسودة',
                'documents_under_review' => 'المستندات قيد المراجعة',
                'documents_rejected'     => 'المستندات مرفوضة',
                'payment_pending'        => 'بانتظار الدفع',
                'payment_completed'      => 'تم الدفع بنجاح',
                'appointment_pending'    => 'بانتظار حجز موعد',
                'in_testing'             => 'قيد الاختبار',
                'waiting_retest'         => 'بانتظار إعادة الاختبار',
                'approved'               => 'مقبول',
                'license_issued'         => 'تم إصدار الرخصة',
                'rejected'               => 'مرفوض',
                'cancelled'              => 'ملغي',
                'administrative_review'  => 'مراجعة إدارية',
                default                  => $code,
            };
        }

        if (str_starts_with($suffix, 'employee.services.')) {
            $code = substr($suffix, strlen('employee.services.'));
            return match ($code) {
                'new_license'         => 'رخصة جديدة',
                'renew_license'       => 'تجديد رخصة',
                'lost_replacement'    => 'بدل ضائع',
                'damaged_replacement' => 'بدل تالف',
                'license_unblock'     => 'فك حظر الرخصة',
                default               => $code,
            };
        }

        if (str_starts_with($suffix, 'employee.applications.')) {
            $code = substr($suffix, strlen('employee.applications.'));
            return match ($code) {
                'tracking_updated' => 'تم تحديث حالة تتبع الطلب بنجاح.',
                'status_invalid' => 'حالة الطلب غير صالحة لهذا الإجراء.',
                'not_found' => 'الطلب غير موجود في النظام.',
                default => 'خطأ في معالجة الطلب.',
            };
        }
        if (str_starts_with($suffix, 'employee.license_types.')) {
            $code = substr($suffix, strlen('employee.license_types.'));
            return match ($code) {
                'private' => 'خصوصي',
                'public'  => 'عمومي',
                'truck'   => 'شاحنة',
                'bus'     => 'حافلة',
                default   => $code,
            };
        }

        if (str_starts_with($suffix, 'employee.test_types.')) {
            $code = substr($suffix, strlen('employee.test_types.'));
            return match ($code) {
                'vision'    => 'فحص النظر',
                'theory'    => 'الفحص النظري',
                'practical' => 'الفحص العملي',
                default     => $code,
            };
        }

        return match ($suffix) {
            'employee.generic.success' => 'تمت العملية بنجاح.',
            'employee.generic.error' => 'حدث خطأ.',
            'Applications list retrieved successfully.' => 'تم جلب قائمة الطلبات بنجاح.',
            default => 'عذراً، تعذر عرض الرسالة حالياً.',
        };
    }
}
