<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\ApplicationService;

class AgentDuplicateApplicationGuard
{
    public function __construct(
        private readonly ApplicationService $applications,
    ) {}

    public function findExistingActive(
        User $citizen,
        string $licenseTypeCode,
        string $serviceTypeCode,
    ): ?LicenseApplication {
        return $this->applications->findActiveApplicationByCodes(
            $citizen,
            $licenseTypeCode,
            $serviceTypeCode
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function blockCreateApplicationIfDuplicate(
        User $citizen,
        array $payload,
        string $licenseTypeCode,
        string $serviceTypeCode,
    ): array {
        $existing = $this->findExistingActive($citizen, $licenseTypeCode, $serviceTypeCode);

        if ($existing === null) {
            return $payload;
        }

        $existing->loadMissing(['licenseType', 'serviceType']);

        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        $label = LicenseTypeSlotExtractor::labelAr(
            $existing->licenseType?->code ?? $licenseTypeCode
        );

        $payload['intent'] = AgentIntent::CreateNewLicenseApplication->value;
        $payload['missing_slots'] = [];
        $payload['proposed_action'] = [
            'name' => 'get_application_status',
            'arguments' => [
                'application_id' => $existing->id,
            ],
        ];
        $payload['requires_confirmation'] = true;
        $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.9);
        $payload['requires_human_support'] = false;
        $payload['safety_status'] = 'safe';
        $payload['reply'] = $language === 'ar'
            ? __('messages.ai_agent.existing_active_application', ['label' => $label])
            : 'You already have an active '.$licenseTypeCode.' license application. You can continue the existing application instead of creating a new one.';

        return $payload;
    }
}
