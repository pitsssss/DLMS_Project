<?php

namespace App\Modules\AIAgent\DTO;

use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use Illuminate\Support\Collection;

class AgentWorkflowContext
{
    /**
     * @param  array<string, mixed>  $state
     * @param  Collection<int, LicenseApplication>|null  $applicationChoices
     */
    public function __construct(
        public readonly User $citizen,
        public readonly AIAgentSession $session,
        public readonly string $message,
        public readonly string $language,
        public readonly array $state,
        public readonly ?int $lastApplicationId = null,
        public readonly ?LicenseApplication $targetApplication = null,
        public readonly ?Collection $applicationChoices = null,
        public readonly ?string $profileStatus = null,
    ) {}

    public function requiresApplication(): bool
    {
        return $this->targetApplication !== null
            || $this->applicationChoices !== null
            || $this->lastApplicationId !== null;
    }

    public function hasMultipleApplications(): bool
    {
        return $this->applicationChoices !== null && $this->applicationChoices->count() > 1;
    }

    public function hasNoApplications(): bool
    {
        return $this->targetApplication === null
            && ($this->applicationChoices === null || $this->applicationChoices->isEmpty());
    }
}
