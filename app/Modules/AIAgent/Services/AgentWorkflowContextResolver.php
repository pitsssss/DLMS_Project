<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Models\AIAgentSession;
use Illuminate\Support\Collection;

class AgentWorkflowContextResolver
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
        private readonly AgentApplicationNextStepService $nextStepService,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function resolve(
        User $citizen,
        AIAgentSession $session,
        string $message,
        string $language,
        array $state,
    ): AgentWorkflowContext {
        $lastApplicationId = $this->sessionContext->resolveLastDiscussedApplicationId($session);
        $resolution = $this->nextStepService->resolveTargetApplication($citizen, $session);

        $target = $resolution instanceof LicenseApplication ? $resolution : null;
        $choices = $resolution instanceof Collection ? $resolution : null;

        return new AgentWorkflowContext(
            citizen: $citizen,
            session: $session,
            message: $message,
            language: $language,
            state: $state,
            lastApplicationId: $lastApplicationId,
            targetApplication: $target,
            applicationChoices: $choices,
            profileStatus: (string) ($citizen->profile_status?->value ?? $citizen->profile_status ?? ''),
        );
    }
}
