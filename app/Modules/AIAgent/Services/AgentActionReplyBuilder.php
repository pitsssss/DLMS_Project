<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentActionReplyBuilder
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function success(AIAgentAction $action, array $result): string
    {
        return match ($action->action_name) {
            'create_application' => $this->createApplicationSuccessReply($action, $result),
            'get_application_status' => $this->applicationStatusReply($result),
            'get_required_documents' => 'تم جلب قائمة الوثائق المطلوبة للطلب.',
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
        $status = (string) ($result['status'] ?? '');

        return "حالة الطلب {$number} هي: {$status}.";
    }
}
