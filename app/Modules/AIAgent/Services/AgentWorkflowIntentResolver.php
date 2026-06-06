<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;

class AgentWorkflowIntentResolver
{
    public function resolve(
        string $message,
        ?string $lastSessionIntent = null,
        ?int $lastDiscussedApplicationId = null,
    ): ?AgentIntent {
        return AgentWorkflowPhraseMatcher::resolveIntent(
            $message,
            $lastSessionIntent,
            $lastDiscussedApplicationId
        );
    }
}
