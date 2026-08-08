<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentTranslator;

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
            'reply' => AgentTranslator::message('ai_agent.fines.loading'),
            'proposed_action' => [
                'name' => 'get_fines',
                'arguments' => [],
            ],
            'execute_immediately' => true,
        ]);
    }
}
