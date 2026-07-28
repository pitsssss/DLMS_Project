<?php

namespace App\Support;

class EmployeeMessageTranslator
{
    public static function get(string $key, array $replace = []): string
    {
        $fullKey = str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;

        $translated = ArabicMessageTranslator::resolve($fullKey, $replace);

        if ($translated !== null) {
            return $translated;
        }

        return self::fallback($fullKey, $replace);
    }

    private static function fallback(string $fullKey, array $replace): string
    {
        $suffix = str_replace('messages.', '', $fullKey);

        if (str_starts_with($suffix, 'employee.statuses.')) {
            $code = substr($suffix, strlen('employee.statuses.'));
            return match ($code) {
                'draft'                  => 'مسودة',
                'documents_under_review' => 'مراجعة الوثائق',
                'documents_rejected'     => 'رفض الوثائق',
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

        if (str_starts_with($suffix, 'employee.profile_statuses.')) {
            $code = substr($suffix, strlen('employee.profile_statuses.'));
            return match ($code) {
                'incomplete'     => 'غير مكتمل',
                'pending_review' => 'قيد المراجعة',
                'approved'       => 'مقبول',
                'rejected'       => 'مرفوض',
                default          => $code,
            };
        }

        if (str_starts_with($suffix, 'employee.services.')) {
            $code = substr($suffix, strlen('employee.services.'));
            return match ($code) {
                'new_license'         => 'إصدار رخصة جديدة',
                'renew_license'       => 'تجديد رخصة',
                'lost_replacement'    => 'بدل فاقد',
                'damaged_replacement' => 'بدل تالف',
                'license_unblock'     => 'فك حظر رخصة',
                default               => $code,
            };
        }

        if (str_starts_with($suffix, 'employee.applications.')) {
            $code = substr($suffix, strlen('employee.applications.'));

            return match ($code) {
                'list_retrieved' => 'تم جلب قائمة الطلبات بنجاح.',
                'details_retrieved' => 'تم جلب تفاصيل الطلب بنجاح.',
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
        if (str_starts_with($suffix, 'employee.audit.actions.')) {
            $code = substr($suffix, strlen('employee.audit.actions.'));

            return match ($code) {
                'created' => 'تم إنشاء الطلب',
                'updated' => 'تم تعديل الطلب',
                'status_changed' => 'تم تغيير حالة الطلب',
                'documents_uploaded' => 'تم رفع الوثائق',
                'documents_reviewed' => 'تمت مراجعة الوثائق',
                'payment_completed' => 'تم الدفع',
                'appointment_booked' => 'تم حجز موعد اختبار',
                'test_result_recorded' => 'تم تسجيل نتيجة الاختبار',
                'approved' => 'تمت الموافقة على الطلب',
                'license_issued' => 'تم إصدار الرخصة',
                'cancelled' => 'تم إلغاء الطلب',
                'submitted' => 'تم إرسال الطلب',
                default => $code,
            };
        }
        if (str_starts_with($suffix, 'employee.audit.fields.')) {
            $code = substr($suffix, strlen('employee.audit.fields.'));

            return match ($code) {
                'application_number' => 'رقم الطلب',
                'status' => 'الحالة',
                'citizen_id' => 'المواطن',
                'user_id' => 'المستخدم',
                'license_type_id' => 'فئة الرخصة',
                'license_type_code' => 'فئة الرخصة',
                'service_type_id' => 'نوع الخدمة',
                'service_type_code' => 'نوع الخدمة',
                'current_test_type_id' => 'نوع الاختبار الحالي',
                'current_test_type_code' => 'نوع الاختبار الحالي',
                'test_type_id' => 'نوع الاختبار الحالي',
                'test_type_code' => 'نوع الاختبار الحالي',
                'rejection_reason' => 'سبب الرفض',
                'approved_at' => 'تاريخ الموافقة',
                'issued_at' => 'تاريخ الإصدار',
                'submitted_at' => 'تاريخ إرسال الطلب',
                'created_at' => 'تاريخ الإنشاء',
                'updated_at' => 'آخر تحديث',
                default => $code,
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
