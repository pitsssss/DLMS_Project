<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;

class AgentFinesHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context): array
    {
        return $this->responseBuilder->basePayload(AgentIntent::GetFines, $context->language, [
            'reply' => 'سأعرض لك مخالفاتك الحالية.',
            'proposed_action' => [
                'name' => 'get_fines',
                'arguments' => [],
            ],
        ]);
    }
}
