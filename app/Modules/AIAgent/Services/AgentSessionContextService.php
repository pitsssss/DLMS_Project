<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentSessionContextService
{
    public function __construct(
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
    ) {}

    /**
     * @return array{
     *   intent: string|null,
     *   missing_slots: list<string>,
     *   collected_slots: array<string, mixed>,
     *   service_type_code: string
     * }
     */
    public function resolveState(AIAgentSession $session): array
    {
        $context = $session->context ?? [];

        $collected = $context['collected_slots'] ?? [];
        if (! is_array($collected)) {
            $collected = [];
        }

        if (isset($context['license_type_code']) && ! isset($collected['license_type_code'])) {
            $collected['license_type_code'] = $context['license_type_code'];
        }

        $missing = $context['missing_slots'] ?? [];
        if (! is_array($missing)) {
            $missing = [];
        }

        return [
            'intent' => $session->current_intent ?? ($context['intent'] ?? null),
            'missing_slots' => array_values($missing),
            'collected_slots' => $collected,
            'service_type_code' => (string) ($context['service_type_code'] ?? 'new_license'),
        ];
    }

    /**
     * @return array{
     *   intent: string|null,
     *   missing_slots: list<string>,
     *   collected_slots: array<string, mixed>,
     *   service_type_code: string,
     *   extracted_license_type: string|null
     * }
     */
    public function mergeUserMessage(AIAgentSession $session, string $userMessage): array
    {
        $state = $this->resolveState($session);
        $extracted = LicenseTypeSlotExtractor::extract($userMessage);

        if ($extracted !== null) {
            $state['collected_slots']['license_type_code'] = $extracted;
        }

        $state['extracted_license_type'] = $extracted;

        return $state;
    }

    public function isNewLicenseContinuation(array $state, ?string $extractedLicenseType = null): bool
    {
        if (($state['intent'] ?? null) === AgentIntent::CreateNewLicenseApplication->value) {
            return true;
        }

        if (in_array('license_type', $state['missing_slots'] ?? [], true)) {
            return true;
        }

        return $extractedLicenseType !== null
            && in_array($state['intent'] ?? null, [
                AgentIntent::CreateNewLicenseApplication->value,
                null,
            ], true)
            && (
                in_array('license_type', $state['missing_slots'] ?? [], true)
                || ($state['intent'] ?? null) === AgentIntent::CreateNewLicenseApplication->value
            );
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function finalizeState(array $state, array $payload, string $userMessage): array
    {
        $extracted = LicenseTypeSlotExtractor::extract($userMessage);

        if ($extracted !== null) {
            $state['collected_slots']['license_type_code'] = $extracted;
        }

        $licenseFromAction = $payload['proposed_action']['arguments']['license_type_code'] ?? null;
        if (is_string($licenseFromAction) && $licenseFromAction !== '') {
            $state['collected_slots']['license_type_code'] = $licenseFromAction;
        }

        $state['intent'] = $payload['intent'] ?? $state['intent'];
        $state['missing_slots'] = array_values($payload['missing_slots'] ?? []);

        return $state;
    }

    /**
     * Deterministic override when the citizen is answering a previous slot question.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyContinuity(
        User $citizen,
        AIAgentSession $session,
        array $payload,
        array $state,
        string $userMessage,
    ): array {
        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        $licenseType = $state['collected_slots']['license_type_code']
            ?? $state['extracted_license_type']
            ?? LicenseTypeSlotExtractor::extract($userMessage);

        if (! $this->isNewLicenseContinuation($state, $licenseType)) {
            return $payload;
        }

        $serviceTypeCode = $state['service_type_code'] ?? 'new_license';

        if ($licenseType === null) {
            $payload['intent'] = AgentIntent::CreateNewLicenseApplication->value;
            $payload['missing_slots'] = ['license_type'];
            $payload['proposed_action'] = null;
            $payload['requires_confirmation'] = false;
            $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.75);

            if ($this->shouldReplaceReply($payload)) {
                $payload['reply'] = $language === 'ar'
                    ? 'يمكنني مساعدتك في إنشاء طلب رخصة جديدة. ما نوع الرخصة التي تريدها؟ خاصة، عامة، شاحنة، أم حافلة؟'
                    : 'I can help you prepare a new license application. Which license type do you need: private, public, truck, or bus?';
            }

            return $payload;
        }

        $payload['intent'] = AgentIntent::CreateNewLicenseApplication->value;
        $payload['missing_slots'] = [];
        $payload['proposed_action'] = [
            'name' => 'create_application',
            'arguments' => [
                'license_type_code' => $licenseType,
                'service_type_code' => $serviceTypeCode,
            ],
        ];
        $payload['requires_confirmation'] = true;
        $payload['confidence'] = max((float) ($payload['confidence'] ?? 0), 0.88);
        $payload['requires_human_support'] = false;
        $payload['safety_status'] = 'safe';

        $payload = $this->duplicateGuard->blockCreateApplicationIfDuplicate(
            $citizen,
            $payload,
            $licenseType,
            $serviceTypeCode
        );

        if (
            ($payload['proposed_action']['name'] ?? null) === 'create_application'
            && $this->shouldReplaceReply($payload)
        ) {
            $label = LicenseTypeSlotExtractor::labelAr($licenseType);
            $payload['reply'] = $language === 'ar'
                ? "سيتم تجهيز طلب إصدار رخصة قيادة {$label}. هل تؤكد المتابعة؟"
                : "I will prepare a new {$licenseType} license application. Do you want to continue?";
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function buildPersistedContext(
        AIAgentSession $session,
        array $payload,
        array $state,
        ?AIAgentAction $pendingAction = null,
    ): array {
        $collected = $state['collected_slots'] ?? [];

        if (! empty($payload['proposed_action']['arguments']['license_type_code'])) {
            $collected['license_type_code'] = $payload['proposed_action']['arguments']['license_type_code'];
        }

        $context = [
            'intent' => $payload['intent'] ?? $session->current_intent,
            'missing_slots' => array_values($payload['missing_slots'] ?? []),
            'collected_slots' => $collected,
            'service_type_code' => $state['service_type_code'] ?? 'new_license',
        ];

        if ($pendingAction !== null) {
            $context['last_proposed_action'] = [
                'id' => $pendingAction->id,
                'name' => $pendingAction->action_name,
                'status' => $pendingAction->status->value,
                'arguments' => $pendingAction->arguments ?? [],
            ];
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldReplaceReply(array $payload): bool
    {
        $intent = $payload['intent'] ?? null;

        return in_array($intent, [
            AgentIntent::GeneralHelp->value,
            AgentIntent::Unknown->value,
        ], true)
            || ((float) ($payload['confidence'] ?? 1)) < 0.6;
    }
}
