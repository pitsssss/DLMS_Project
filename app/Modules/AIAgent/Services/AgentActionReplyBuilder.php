<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Enums\ApplicationStatus;

class AgentActionReplyBuilder
{
    public function __construct(
        private readonly AgentRequiredDocumentsHandler $requiredDocumentsHandler,
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
            'get_fines' => 'تم جلب مخالفاتك.',
            'get_licenses' => 'تم جلب رخص القيادة الخاصة بك.',
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
}
