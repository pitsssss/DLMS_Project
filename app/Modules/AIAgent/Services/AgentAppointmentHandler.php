<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;

class AgentAppointmentHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentApplicationActionPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context, AgentIntent $intent): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->multipleApplicationsReply('appointment', $context->applicationChoices, $context->language),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
            ]);
        }

        $actionName = $intent === AgentIntent::BookAppointment ? 'book_appointment' : 'get_appointment_slots';
        $blockReason = $this->policy->blockReason($application, $actionName);

        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload($intent, $context->language, $blockReason);
        }

        if ($intent === AgentIntent::BookAppointment) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => 'يمكنني مساعدتك في حجز موعد الاختبار. يرجى اختيار الموعد المناسب من المواعيد المتاحة. هل تريد عرض المواعيد المتاحة أولاً؟',
                'proposed_action' => [
                    'name' => 'get_appointment_slots',
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => false,
            ]);
        }

        return $this->responseBuilder->basePayload(AgentIntent::GetAppointmentSlots, $context->language, [
            'reply' => 'سأعرض لك المواعيد المتاحة لاختبار طلبك. يرجى اختيار الموعد المناسب من القائمة.',
            'proposed_action' => [
                'name' => 'get_appointment_slots',
                'arguments' => ['application_id' => $application->id],
            ],
        ]);
    }
}
