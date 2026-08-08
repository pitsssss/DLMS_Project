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

    private function isEnglish(): bool
    {
        return method_exists(AgentTranslator::class, 'getLocale') 
            && AgentTranslator::getLocale() === 'en';
    }

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
            'get_payment_status' => $this->paymentStatusReply($result),
            'get_profile_status' => $this->profileStatusReply($result),
            'start_payment' => $this->startPaymentReply($result),
            'get_fines' => $this->finesReply($result),
            'get_licenses' => $this->licensesReply($result),
            'get_available_tests' => $this->availableTestsHandler->replyFromActionResult($result),
            'get_appointment_slots' => $this->appointmentHandler->replyFromSlotsResult($result),
            'get_current_appointments' => $this->appointmentHandler->replyFromCurrentAppointmentsResult($result),
            'book_appointment' => $this->bookAppointmentReply($result),
            'reschedule_appointment' => $this->rescheduleAppointmentReply($result),
            'cancel_appointment' => $this->cancelAppointmentReply($result),
            'get_test_results' => $this->testResultsReply($result),
            'submit_documents_for_review' => $this->submitDocumentsReply($result),
            default => AgentTranslator::getLocale() === 'en' 
                ? 'Operation completed successfully.'
                : 'تم تنفيذ العملية بنجاح.',
        };
    }

    public function cancel(): string
    {
        return AgentTranslator::getLocale() === 'en'
            ? 'The operation has been cancelled. You can request help again at any time.'
            : 'تم إلغاء العملية. يمكنك طلب المساعدة من جديد في أي وقت.';
    }

    public function failure(string $message): string
    {
        $prefix = AgentTranslator::getLocale() === 'en'
            ? 'Failed to execute operation: '
            : 'تعذر تنفيذ العملية: ';
        
        return $prefix.$message;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function createApplicationSuccessReply(AIAgentAction $action, array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        $arguments = is_array($action->arguments) ? $action->arguments : [];
        $licenseCode = (string) ($arguments['license_type_code'] ?? 'private');
        
        if (AgentTranslator::getLocale() === 'en') {
            $label = LicenseTypeSlotExtractor::labelEn($licenseCode);
            return "{$label} driving license application created successfully. Application number is {$number}. The next step is to upload the required documents.";
        }
        
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

        return AgentTranslator::message('ai_agent.payment.fee.reply', [
            'number' => $number,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function paymentStatusReply(array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        if (! empty($result['is_paid'])) {
            return AgentTranslator::message('ai_agent.payment.status.paid', ['number' => $number]);
        }
        if (! empty($result['is_awaiting_payment'])) {
            return AgentTranslator::message('ai_agent.payment.status.pending', ['number' => $number]);
        }

        return AgentTranslator::message('ai_agent.payment.status.unknown');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function profileStatusReply(array $result): string
    {
        $status = (string) ($result['profile_status'] ?? '');
        $isEn = AgentTranslator::getLocale() === 'en';

        return match ($status) {
            'approved' => $isEn 
                ? 'Your profile has been approved. You can use application and license services.'
                : 'تمت الموافقة على ملفك الشخصي. يمكنك استخدام خدمات الطلبات والرخص.',
            'pending_review' => $isEn
                ? 'Your profile is currently under review.'
                : 'ملفك الشخصي قيد المراجعة حالياً.',
            'rejected' => $isEn
                ? 'Your profile data has been rejected. Please update the information and resubmit for review.'
                : 'تم رفض بيانات ملفك الشخصي. يرجى تعديل البيانات وإعادة إرسالها للمراجعة.',
            default => $isEn
                ? 'Your profile status: '.$status
                : 'حالة ملفك الشخصي: '.$status,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function startPaymentReply(array $result): string
    {
        $number = (string) ($result['application_number'] ?? '');
        $isEn = AgentTranslator::getLocale() === 'en';

        if (! empty($result['checkout_url'])) {
            return $isEn
                ? "Payment for application {$number} is ready. You can complete the payment from the payment link displayed in the app."
                : "تم تجهيز دفع رسوم الطلب {$number}. يمكنك إكمال الدفع من رابط الدفع المعروض في التطبيق.";
        }

        return $isEn
            ? "Payment for application {$number} has been prepared successfully."
            : "تم تجهيز دفع رسوم الطلب {$number} بنجاح.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finesReply(array $result): string
    {
        $count = is_array($result['items'] ?? null) ? count($result['items']) : 0;
        $isEn = AgentTranslator::getLocale() === 'en';

        return $count === 0
            ? ($isEn 
                ? 'You currently have no fines registered on your account.'
                : 'لا توجد مخالفات مسجلة على حسابك حالياً.')
            : ($isEn
                ? "You have {$count} fine(s) registered. You can review the details in the result."
                : "لديك {$count} مخالفة مسجلة. يمكنك مراجعة التفاصيل في النتيجة.");
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bookAppointmentReply(array $result): string
    {
        $testName = trim((string) ($result['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback')));
        $date = trim((string) ($result['date'] ?? ''));
        $time = trim((string) ($result['start_time'] ?? ''));

        if ($date !== '' && $time !== '') {
            return AgentTranslator::message('ai_agent.appointments.book.success', [
                'test' => $testName,
                'date' => $date,
                'time' => $time,
            ]);
        }

        return AgentTranslator::message('ai_agent.appointments.book.success_short', [
            'test' => $testName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function rescheduleAppointmentReply(array $result): string
    {
        $testName = trim((string) ($result['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback')));
        $date = trim((string) ($result['date'] ?? ''));
        $time = trim((string) ($result['start_time'] ?? ''));

        return AgentTranslator::message('ai_agent.appointments.reschedule.success', [
            'test' => $testName,
            'date' => $date,
            'time' => $time,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function cancelAppointmentReply(array $result): string
    {
        $testName = trim((string) ($result['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback')));

        return AgentTranslator::message('ai_agent.appointments.cancel.success', [
            'test' => $testName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function licensesReply(array $result): string
    {
        $count = is_array($result['items'] ?? null) ? count($result['items']) : 0;
        $isEn = AgentTranslator::getLocale() === 'en';

        return $count === 0
            ? ($isEn
                ? 'You currently have no driving licenses issued on your account.'
                : 'لا توجد رخص قيادة صادرة على حسابك حالياً.')
            : ($isEn
                ? "You have {$count} license(s) registered. You can review the details in the result."
                : "لديك {$count} رخصة/رخص مسجلة. يمكنك مراجعة التفاصيل في النتيجة.");
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function testResultsReply(array $result): string
    {
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];

        if ($items === []) {
            return 'لا توجد نتائج اختبار مسجلة لهذا الطلب حالياً.';
        }

        $lines = collect($items)
            ->map(function (array $item): string {
                $testName = trim((string) ($item['test_type']['name'] ?? 'الاختبار'));
                $status = (string) ($item['result'] ?? '');
                $translated = $status !== '' ? __('messages.tests.result_'.$status) : '';
                $attempt = (string) ($item['attempt_number'] ?? '');
                $date = trim((string) ($item['recorded_at'] ?? ''));

                $datePart = $date !== '' ? substr($date, 0, 10) : '';
                $suffix = $datePart !== '' ? " بتاريخ {$datePart}" : '';

                $attemptPart = $attempt !== '' ? " (المحاولة {$attempt})" : '';

                return "- {$testName}: {$translated}{$attemptPart}{$suffix}.";
            })
            ->implode("\n");

        return "هذه نتائج الاختبارات المسجلة لديك:\n{$lines}";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function submitDocumentsReply(array $result): string
    {
        $number = trim((string) ($result['application_number'] ?? ''));
        $isEn = AgentTranslator::getLocale() === 'en';
        
        $status = $isEn 
            ? trim((string) ($result['status_label_en'] ?? 'under review'))
            : trim((string) ($result['status_label_ar'] ?? 'قيد المراجعة'));

        if ($number !== '') {
            return $isEn
                ? "Documents for application {$number} have been submitted for review. Application status is now: {$status}."
                : "تم إرسال وثائق طلب {$number} للمراجعة. حالة الطلب الآن: {$status}.";
        }

        return $isEn
            ? "Your application documents have been submitted for review. Application status is now: {$status}."
            : "تم إرسال وثائق طلبك للمراجعة. حالة الطلب الآن: {$status}.";
    }
}
