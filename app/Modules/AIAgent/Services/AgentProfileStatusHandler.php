<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ProfileStatus;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\Auth\Services\ProfileService;

class AgentProfileStatusHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly ProfileService $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context): array
    {
        $payload = $this->profiles->statusPayload($context->citizen);
        $status = (string) ($payload['profile_status'] ?? '');

        $reply = match ($status) {
            ProfileStatus::Approved->value => 'تمت الموافقة على ملفك الشخصي. يمكنك استخدام خدمات الطلبات والرخص.',
            ProfileStatus::PendingReview->value => 'ملفك الشخصي قيد المراجعة حالياً. سيتم إعلامك عند الانتهاء من المراجعة.',
            ProfileStatus::Rejected->value => 'تم رفض بيانات ملفك الشخصي. يرجى تعديل البيانات وإعادة إرسالها للمراجعة.'
                .(($payload['profile_rejection_reason'] ?? '') !== '' ? ' السبب: '.$payload['profile_rejection_reason'] : ''),
            default => ! ($payload['profile_completed'] ?? false)
                ? 'يرجى إكمال بيانات الملف الشخصي قبل استخدام خدمات الطلبات.'
                : 'حالة ملفك الشخصي: '.$status,
        };

        return $this->responseBuilder->basePayload(AgentIntent::GetProfileStatus, $context->language, [
            'reply' => $reply,
            'proposed_action' => [
                'name' => 'get_profile_status',
                'arguments' => [],
            ],
        ]);
    }
}
