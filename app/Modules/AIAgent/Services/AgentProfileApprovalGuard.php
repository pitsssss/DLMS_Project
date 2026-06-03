<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\Auth\Services\ProfileService;

class AgentProfileApprovalGuard
{
    public function __construct(
        private readonly ProfileService $profiles,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function blockCreateApplicationIfProfileNotApproved(User $citizen, array $payload): array
    {
        $proposed = $payload['proposed_action'] ?? null;
        $isCreateAction = is_array($proposed) && ($proposed['name'] ?? '') === 'create_application';
        $isCreateIntent = ($payload['intent'] ?? null) === AgentIntent::CreateNewLicenseApplication->value;

        if (! $isCreateAction && ! $isCreateIntent) {
            return $payload;
        }

        $error = $this->profiles->profileApprovalErrorForStatus($citizen);

        if ($error === null) {
            return $payload;
        }

        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        $payload['proposed_action'] = null;
        $payload['requires_confirmation'] = false;
        $payload['missing_slots'] = [];
        $payload['requires_human_support'] = false;
        $payload['safety_status'] = 'safe';
        $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.9);
        $payload['reply'] = $language === 'ar'
            ? __($error['conversation'])
            : __($error['key']);

        return $payload;
    }
}
