<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Modules\AIAgent\Support\AgentApplicationStatusMap;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;

class AgentApplicationFlowKnowledge
{
    public static function statusLabel(ApplicationStatus $status): string
    {
        return ApplicationStatusLabelMapper::labelAr($status);
    }

    public static function nextStepAr(ApplicationStatus $status): string
    {
        return AgentApplicationStatusMap::definition($status)['next_step_ar'];
    }

    public static function isActionAllowed(ApplicationStatus $status, string $actionName): bool
    {
        return AgentApplicationStatusMap::isActionAllowed($status, $actionName);
    }

    /**
     * @return list<string>
     */
    public static function suggestedReadActions(ApplicationStatus $status): array
    {
        return AgentApplicationStatusMap::definition($status)['allowed_read_actions'];
    }
}
