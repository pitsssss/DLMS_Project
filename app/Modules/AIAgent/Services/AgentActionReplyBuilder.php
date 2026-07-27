<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Enums\ApplicationStatus;
use App\Modules\Payments\Support\ApplicationFeeCatalog;

class AgentActionReplyBuilder
{
    public function __construct(
        private readonly AgentRequiredDocumentsHandler $requiredDocumentsHandler,
        private readonly AgentAvailableTestsHandler $availableTestsHandler,
        private readonly AgentAppointmentHandler $appointmentHandler,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public function success(AIAgentAction $action, array $result): string
    {
        return match ($action->action_name) {
            'create_application' => $this->createApplicationSuccessReply($action, $result),
            'get_application_status' => $this->applicationStatusReply($result),
            'get_application_next_step' => (string) ($result['next_step_message'] ?? $this->applicationStatusReply($result)),
            'get_required_documents' => $this->requiredDocumentsHandler->replyFromActionResult($result),
            'get_application_fee' => $this->applicationFeeReply($result),
            'get_profile_status' => $this->profileStatusReply($result),
            'start_payment' => $this->startPaymentReply($result),
            'get_fines' => $this->finesReply($result),
            'get_licenses' => $this->licensesReply($result),
            'get_available_tests' => $this->availableTestsHandler->replyFromActionResult($result),
            'get_appointment_slots' => $this->appointmentHandler->replyFromSlotsResult($result),
            'get_current_appointments' => $this->appointmentHandler->replyFromCurrentAppointmentsResult($result),
            'book_appointment' => $this->bookAppointmentReply($result),
            default => 'تم تنفيذ العملية بنجاح.',
        };
    }

    public function cancel(): string
    {
        return 'تم إلغاء العملية. يمكنك طلب المساعدة من جديد في أي وقت.';
    }

    public function failure(string $message): string
    {
        return 'تعذر تنفيذ العملية: '.$message;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function createApplicationSuccessReply(AIAgentAction $action, array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        $arguments = is_array($action->arguments) ? $action->arguments : [];
        $licenseCode = (string) ($arguments['license_type_code'] ?? 'private');
        $label = LicenseTypeSlotExtractor::labelAr($licenseCode);

        return "تم إنشاء طلب رخصة القيادة {$label} بنجاح. رقم الطلب هو {$number}. الخطوة التالية هي رفع الوثائق المطلوبة.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applicationStatusReply(array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        $statusLabel = (string) ($result['status_label_ar'] ?? '');
        if ($statusLabel === '') {
            $statusLabel = ApplicationStatusLabelMapper::labelAr(
                ApplicationStatus::tryFrom((string) ($result['status'] ?? ''))
            );
        }

        $nextStep = (string) ($result['next_step_message'] ?? '');
        if ($nextStep !== '') {
            return AgentTranslator::message('ai_agent.application_status.with_next_step', [
                'number' => $number,
                'status' => $statusLabel,
                'next_step' => $nextStep,
            ]);
        }

        return "حالة الطلب {$number} هي: {$statusLabel}.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applicationFeeReply(array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        $amount = (string) ($result['fee']['amount'] ?? '');
        $currency = (string) ($result['fee']['currency'] ?? ApplicationFeeCatalog::CURRENCY);

        return "رسوم طلب {$number} هي {$amount} {$currency}.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function profileStatusReply(array $result): string
    {
        $status = (string) ($result['profile_status'] ?? '');

        return match ($status) {
            'approved' => 'تمت الموافقة على ملفك الشخصي. يمكنك استخدام خدمات الطلبات والرخص.',
            'pending_review' => 'ملفك الشخصي قيد المراجعة حالياً.',
            'rejected' => 'تم رفض بيانات ملفك الشخصي. يرجى تعديل البيانات وإعادة إرسالها للمراجعة.',
            default => 'حالة ملفك الشخصي: '.$status,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function startPaymentReply(array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');

        if (! empty($result['checkout_url'])) {
            return "تم تجهيز دفع رسوم الطلب {$number}. يمكنك إكمال الدفع من رابط الدفع المعروض في التطبيق.";
        }

        return "تم تجهيز دفع رسوم الطلب {$number} بنجاح.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finesReply(array $result): string
    {
        $count = is_array($result['items'] ?? null) ? count($result['items']) : 0;

        return $count === 0
            ? 'لا توجد مخالفات مسجلة على حسابك حالياً.'
            : "لديك {$count} مخalفة مسجلة. يمكنك مراجعة التفاصيل في النتيجة.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bookAppointmentReply(array $result): string
    {
        $testName = trim((string) ($result['test_type']['name'] ?? 'الاختبار'));
        $date = trim((string) ($result['date'] ?? ''));
        $time = trim((string) ($result['start_time'] ?? ''));

        if ($date !== '' && $time !== '') {
            return AgentTranslator::message('ai_agent.appointments.current.single', [
                'test' => $testName,
                'date' => $date,
                'time' => $time,
            ]);
        }

        return 'تم حجز موعد '.$testName.' بنجاح.';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function licensesReply(array $result): string
    {
        $count = is_array($result['items'] ?? null) ? count($result['items']) : 0;

        return $count === 0
            ? 'لا توجد رخص قيادة صادرة على حسابك حالياً.'
            : "لديك {$count} رخصة/رخص مسجلة. يمكنك مراجعة التفاصيل في النتيجة.";
    }
}
