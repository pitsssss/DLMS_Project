<?php

namespace App\Modules\AIAgent\Support;

use App\Enums\ApplicationStatus;

class AgentApplicationStatusMap
{
    /**
     * @return array{
     *   label_ar: string,
     *   next_step_ar: string,
     *   allowed_read_actions: list<string>,
     *   allowed_mutating_actions: list<string>,
     *   blocked_actions: list<string>
     * }
     */
    public static function definition(ApplicationStatus $status): array
    {
        return match ($status) {
            ApplicationStatus::Draft => [
                'label_ar' => 'مسودة',
                'next_step_ar' => 'رفع الوثائق المطلوبة ثم إرسالها للمراجعة',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_required_documents'],
                'allowed_mutating_actions' => ['submit_documents_for_review'],
                'blocked_actions' => ['start_payment', 'get_appointment_slots', 'book_appointment', 'get_available_tests'],
            ],
            ApplicationStatus::DocumentsUnderReview => [
                'label_ar' => 'قيد مراجعة الوثائق',
                'next_step_ar' => 'انتظار مراجعة الموظف',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_required_documents'],
                'allowed_mutating_actions' => [],
                'blocked_actions' => ['start_payment', 'book_appointment', 'submit_documents_for_review'],
            ],
            ApplicationStatus::DocumentsRejected => [
                'label_ar' => 'تم رفض الوثائق',
                'next_step_ar' => 'مراجعة سبب الرفض وإعادة رفع الوثائق',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_required_documents'],
                'allowed_mutating_actions' => ['submit_documents_for_review'],
                'blocked_actions' => ['start_payment', 'book_appointment'],
            ],
            ApplicationStatus::PaymentPending => [
                'label_ar' => 'بانتظار الدفع',
                'next_step_ar' => 'دفع الرسوم',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_application_fee', 'get_payment_status'],
                'allowed_mutating_actions' => ['start_payment'],
                'blocked_actions' => ['book_appointment', 'get_appointment_slots'],
            ],
            ApplicationStatus::PaymentCompleted => [
                'label_ar' => 'تم الدفع',
                'next_step_ar' => 'حجز موعد الاختبار الأول المتاح',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_available_tests', 'get_appointment_slots'],
                'allowed_mutating_actions' => ['book_appointment'],
                'blocked_actions' => ['start_payment'],
            ],
            ApplicationStatus::AppointmentPending => [
                'label_ar' => 'بانتظار حجز موعد',
                'next_step_ar' => 'حجز موعد الاختبار المتاح',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_available_tests', 'get_appointment_slots'],
                'allowed_mutating_actions' => ['book_appointment'],
                'blocked_actions' => ['start_payment'],
            ],
            ApplicationStatus::InTesting => [
                'label_ar' => 'قيد الاختبارات',
                'next_step_ar' => 'متابعة الاختبار الحالي أو انتظار تسجيل النتيجة',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_available_tests', 'get_appointment_slots', 'get_test_results'],
                'allowed_mutating_actions' => ['book_appointment'],
                'blocked_actions' => ['start_payment'],
            ],
            ApplicationStatus::WaitingRetest => [
                'label_ar' => 'بانتظار إعادة الاختبار',
                'next_step_ar' => 'حجز موعد إعادة الاختبار لنفس الاختبار غير المجتاز',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_available_tests', 'get_appointment_slots'],
                'allowed_mutating_actions' => ['book_appointment'],
                'blocked_actions' => ['start_payment'],
            ],
            ApplicationStatus::Approved => [
                'label_ar' => 'مؤهل لإصدار الرخصة',
                'next_step_ar' => 'انتظار إصدار الرخصة من الموظف المختص',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_licenses'],
                'allowed_mutating_actions' => [],
                'blocked_actions' => ['start_payment', 'book_appointment'],
            ],
            ApplicationStatus::AdministrativeReview => [
                'label_ar' => 'قيد المراجعة الإدارية',
                'next_step_ar' => 'انتظار قرار الإدارة',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step'],
                'allowed_mutating_actions' => [],
                'blocked_actions' => ['start_payment', 'book_appointment'],
            ],
            ApplicationStatus::LicenseIssued => [
                'label_ar' => 'تم إصدار الرخصة',
                'next_step_ar' => 'عرض تفاصيل الرخصة',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step', 'get_licenses', 'get_license_details', 'get_required_documents'],
                'allowed_mutating_actions' => [],
                'blocked_actions' => ['start_payment', 'book_appointment', 'create_application'],
            ],
            ApplicationStatus::Rejected => [
                'label_ar' => 'مرفوض',
                'next_step_ar' => 'مراجعة سبب الرفض',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step'],
                'allowed_mutating_actions' => [],
                'blocked_actions' => ['start_payment', 'book_appointment', 'create_application'],
            ],
            ApplicationStatus::Cancelled => [
                'label_ar' => 'ملغى',
                'next_step_ar' => 'يمكن إنشاء طلب جديد إذا رغبت',
                'allowed_read_actions' => ['get_application_status', 'get_application_next_step'],
                'allowed_mutating_actions' => ['create_application'],
                'blocked_actions' => ['start_payment', 'book_appointment'],
            ],
        };
    }

    public static function isActionAllowed(ApplicationStatus $status, string $actionName): bool
    {
        $definition = self::definition($status);
        $actionName = self::normalizeAction($actionName);

        if ($actionName === 'get_current_appointments') {
            return ! in_array($actionName, $definition['blocked_actions'], true);
        }

        if (in_array($actionName, $definition['blocked_actions'], true)) {
            return false;
        }

        return in_array($actionName, $definition['allowed_read_actions'], true)
            || in_array($actionName, $definition['allowed_mutating_actions'], true);
    }

    public static function normalizeAction(string $actionName): string
    {
        return match ($actionName) {
            'get_application_fee', 'get_payment_status' => 'get_application_fee',
            default => $actionName,
        };
    }
}
