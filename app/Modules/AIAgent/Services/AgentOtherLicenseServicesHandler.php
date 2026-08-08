<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ServiceCode;
use App\Models\License;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentCatalogLocalizer;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;

class AgentOtherLicenseServicesHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentProfileApprovalGuard $profileGuard,
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
        private readonly LicenseServiceEligibilityService $eligibility,
        private readonly AgentLicenseOptionService $licenseOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $citizen, ServiceCode $service, string $language = 'ar'): array
    {
        $intent = match ($service) {
            ServiceCode::RenewLicense => AgentIntent::CreateRenewLicenseApplication,
            ServiceCode::LostReplacement => AgentIntent::CreateLostReplacementApplication,
            ServiceCode::DamagedReplacement => AgentIntent::CreateDamagedReplacementApplication,
            default => AgentIntent::GeneralHelp,
        };

        $eligible = $this->licenseOptions->eligibleLicenses($citizen, $service);

        if ($eligible->isEmpty()) {
            return $this->responseBuilder->basePayload($intent, $language, [
                'confidence' => 0.9,
                'reply' => AgentTranslator::message('ai_agent.other_license.none_eligible'),
                'proposed_action' => null,
                'missing_slots' => [],
                'message_type' => 'no_eligible_license',
                'ui_payload' => ['licenses' => []],
            ]);
        }

        if ($eligible->count() > 1) {
            return $this->responseBuilder->basePayload($intent, $language, [
                'confidence' => 0.88,
                'reply' => AgentTranslator::message('ai_agent.other_license.choose'),
                'proposed_action' => null,
                'missing_slots' => ['related_license_id'],
                'collected_slots' => [
                    'service_type_code' => $service->value,
                ],
                'ui_payload' => [
                    'selection_type' => 'license',
                    'service_type_code' => $service->value,
                    'candidate_license_ids' => $eligible->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ],
            ]);
        }

        return $this->confirmationPayload($citizen, $service, $intent, $language, $eligible->first());
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmationPayload(
        User $citizen,
        ServiceCode $service,
        AgentIntent $intent,
        string $language,
        License $license,
    ): array {
        $code = (string) ($license->licenseType?->code ?? '');
        $label = AgentCatalogLocalizer::licenseType($code, null, $language);

        $serviceLabel = AgentTranslator::message('ai_agent.other_license.service.'.$service->value);

        $payload = $this->responseBuilder->basePayload($intent, $language, [
            'confidence' => 0.92,
            'reply' => AgentTranslator::message('ai_agent.other_license.confirm', [
                'label' => $label,
                'number' => $license->license_number,
                'service' => $serviceLabel,
            ]),
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'create_application',
                'arguments' => [
                    'service_type_code' => $service->value,
                    'related_license_id' => $license->id,
                    'license_type_code' => $code !== '' ? $code : null,
                ],
            ],
            'requires_confirmation' => true,
            'execute_immediately' => false,
            'message_type' => 'license_service_confirmation_required',
            'ui_payload' => [
                'requires_confirmation' => true,
                'service_type_code' => $service->value,
                'license' => [
                    'license_number' => $license->license_number,
                    'license_type' => $code,
                    'license_type_label' => $label,
                    'status' => $license->status?->value ?? (string) $license->status,
                    'expiry_date' => $license->expiry_date?->format('Y-m-d'),
                ],
            ],
        ]);

        $payload = $this->profileGuard->blockCreateApplicationIfProfileNotApproved($citizen, $payload);
        $payload = $this->duplicateGuard->blockCreateApplicationIfDuplicateForLicense(
            $citizen,
            $payload,
            $service->value,
            $license->id
        );

        return $payload;
    }
}
