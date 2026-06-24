<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ServiceCode;
use App\Models\License;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;
class AgentOtherLicenseServicesHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentProfileApprovalGuard $profileGuard,
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
        private readonly LicenseServiceEligibilityService $eligibility,
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

        $licenses = License::query()
            ->where('citizen_id', $citizen->id)
            ->with('licenseType')
            ->orderByDesc('id')
            ->get();

        $eligible = $licenses->filter(function (License $license) use ($citizen, $service): bool {
            return $this->eligibility->check($citizen, $license, $service)['allowed'];
        })->values();

        if ($eligible->isEmpty()) {
            return $this->responseBuilder->basePayload($intent, $language, [
                'confidence' => 0.9,
                'reply' => 'لا يوجد لديك رخصة يمكن تنفيذ هذه الخدمة عليها حالياً.',
                'proposed_action' => null,
                'missing_slots' => [],
            ]);
        }

        if ($eligible->count() > 1) {
            return $this->responseBuilder->basePayload($intent, $language, [
                'confidence' => 0.88,
                'reply' => 'لديك أكثر من رخصة. من فضلك اختر الرخصة التي تريد تنفيذ الخدمة عليها.',
                'proposed_action' => null,
                'missing_slots' => ['related_license_id'],
            ]);
        }

        /** @var License $license */
        $license = $eligible->first();
        $label = LicenseTypeSlotExtractor::labelAr((string) ($license->licenseType?->code ?? ''));
        $serviceLabel = match ($service) {
            ServiceCode::RenewLicense => 'تجديد',
            ServiceCode::LostReplacement => 'بدل فاقد',
            ServiceCode::DamagedReplacement => 'بدل تالف',
            default => 'الخدمة',
        };

        $payload = $this->responseBuilder->basePayload($intent, $language, [
            'confidence' => 0.92,
            'reply' => "وجدت لديك رخصة قيادة {$label} رقم {$license->license_number}. هل تريد إنشاء طلب {$serviceLabel} لها؟",
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'create_application',
                'arguments' => [
                    'service_type_code' => $service->value,
                    'related_license_id' => $license->id,
                ],
            ],
            'requires_confirmation' => true,
            'execute_immediately' => false,
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
