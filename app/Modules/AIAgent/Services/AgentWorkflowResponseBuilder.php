<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentWorkflowIntentCatalog;

class AgentWorkflowResponseBuilder
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function basePayload(AgentIntent $intent, string $language, array $overrides = []): array
    {
        $definition = AgentWorkflowIntentCatalog::get($intent->value);

        return array_merge([
            'intent' => $intent->value,
            'confidence' => 0.93,
            'language' => $language,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'execute_immediately' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
            'proposed_action' => null,
            'suggested_next_actions' => $definition['suggested_followups'] ?? [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function blockedPayload(
        AgentIntent $intent,
        string $language,
        string $reply,
        array $overrides = [],
    ): array {
        return $this->basePayload($intent, $language, array_merge([
            'reply' => $reply,
            'proposed_action' => null,
            'requires_confirmation' => false,
            'execute_immediately' => false,
            'safety_status' => 'blocked',
            'suggested_next_actions' => [],
        ], $overrides));
    }
}
