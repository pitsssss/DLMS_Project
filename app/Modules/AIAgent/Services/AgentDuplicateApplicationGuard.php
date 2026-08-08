<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentTranslator;
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

        $code = $existing->licenseType?->code ?? $licenseTypeCode;
        $label = AgentTranslator::getLocale() === 'en'
            ? LicenseTypeSlotExtractor::labelEn($code)
            : LicenseTypeSlotExtractor::labelAr($code);

        $payload['intent'] = AgentIntent::GetApplicationStatus->value;
        $payload['missing_slots'] = [];
        $payload['proposed_action'] = [
            'name' => 'get_application_status',
            'arguments' => [
                'application_id' => $existing->id,
            ],
        ];
        $payload['requires_confirmation'] = true;
        $payload['execute_immediately'] = false;
        $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.9);
        $payload['requires_human_support'] = false;
        $payload['safety_status'] = 'safe';
        $payload['reply'] = AgentTranslator::message('ai_agent.existing_active_application', ['label' => $label]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function blockCreateApplicationIfDuplicateForLicense(
        User $citizen,
        array $payload,
        string $serviceTypeCode,
        int $relatedLicenseId,
    ): array {
        $existing = $this->applications->findActiveApplicationByRelatedLicense(
            $citizen,
            $serviceTypeCode,
            $relatedLicenseId
        );

        if ($existing === null) {
            return $payload;
        }

        $existing->loadMissing(['licenseType', 'serviceType', 'relatedLicense']);

        $payload['intent'] = AgentIntent::GetApplicationStatus->value;
        $payload['missing_slots'] = [];
        $payload['proposed_action'] = [
            'name' => 'get_application_status',
            'arguments' => [
                'application_id' => $existing->id,
            ],
        ];
        $payload['requires_confirmation'] = true;
        $payload['execute_immediately'] = false;
        $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.9);
        $payload['requires_human_support'] = false;
        $payload['safety_status'] = 'safe';
        $payload['reply'] = AgentTranslator::message('ai_agent.existing_active_application_for_license');

        return $payload;
    }
}
