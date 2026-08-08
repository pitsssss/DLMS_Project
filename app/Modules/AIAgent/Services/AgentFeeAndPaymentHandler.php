<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\Payments\Services\ApplicationPaymentService;
use App\Modules\Payments\Support\ApplicationFeeCatalog;

class AgentFeeAndPaymentHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentApplicationActionPolicy $policy,
        private readonly ApplicationPaymentService $payments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context, AgentIntent $intent): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('payment'),
                'proposed_action' => null,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->multipleApplicationsReply('payment', $context->applicationChoices, $context->language),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('payment'),
                'proposed_action' => null,
            ]);
        }

        if ($intent === AgentIntent::GetPaymentStatus) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.payment.status.loading'),
                'proposed_action' => [
                    'name' => 'get_payment_status',
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => false,
                'execute_immediately' => true,
            ]);
        }

        $actionName = $intent === AgentIntent::StartPayment ? 'start_payment' : 'get_application_fee';
        $blockReason = $this->policy->blockReason($application, $actionName);

        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload($intent, $context->language, $blockReason);
        }

        if ($intent === AgentIntent::StartPayment) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.payment.start.confirm'),
                'proposed_action' => [
                    'name' => 'start_payment',
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => true,
                'execute_immediately' => false,
            ]);
        }

        try {
            $feeData = $this->payments->getFeeForApplication($context->citizen, $application->id);
            $amount = (string) ($feeData['fee']->amount ?? '');
            $currency = (string) ($feeData['fee']->currency ?? ApplicationFeeCatalog::CURRENCY);

            return $this->responseBuilder->basePayload(AgentIntent::GetApplicationFee, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.payment.fee.reply', [
                    'number' => $application->application_number,
                    'amount' => $amount,
                    'currency' => $currency,
                ]),
                'proposed_action' => [
                    'name' => 'get_application_fee',
                    'arguments' => ['application_id' => $application->id],
                ],
                'execute_immediately' => true,
            ]);
        } catch (\Throwable) {
            return $this->responseBuilder->blockedPayload(
                AgentIntent::GetApplicationFee,
                $context->language,
                AgentTranslator::message('ai_agent.payment.fee.unavailable')
            );
        }
    }
}
