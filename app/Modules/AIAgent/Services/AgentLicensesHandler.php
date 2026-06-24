<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;

class AgentLicensesHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context): array
    {
        return $this->responseBuilder->basePayload(AgentIntent::GetLicenses, $context->language, [
            'reply' => 'سأعرض لك رخص القيادة الخاصة بك.',
            'proposed_action' => [
                'name' => 'get_licenses',
                'arguments' => [],
            ],
        ]);
    }
}
