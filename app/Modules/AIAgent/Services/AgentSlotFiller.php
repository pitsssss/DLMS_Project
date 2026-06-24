<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentMessageIntentMatcher;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentSlotFiller
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
        private readonly AgentProfileApprovalGuard $profileGuard,
    ) {}

    /**
     * Merge slots from session context and the latest user message into the model payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(User $citizen, AIAgentSession $session, array $payload, string $userMessage, array $state): array
    {
        if (AgentMessageIntentMatcher::isApplicationStatusQuery($userMessage)
            || AgentMessageIntentMatcher::isApplicationNextStepQuery(
                $userMessage,
                $session->current_intent,
                $this->sessionContext->resolveLastDiscussedApplicationId($session)
            )) {
            return $payload;
        }

        $payload = $this->sessionContext->applyContinuity($citizen, $session, $payload, $state, $userMessage);

        $allowExtract = AgentMessageIntentMatcher::shouldExtractLicenseTypeSlot(
            $userMessage,
            $payload['intent'] ?? $state['intent'],
            $state['missing_slots'] ?? []
        );

        $licenseType = $state['collected_slots']['license_type_code']
            ?? LicenseTypeSlotExtractor::extract($userMessage, $allowExtract);

        if (($payload['intent'] ?? null) === AgentIntent::CreateNewLicenseApplication->value) {
            if ($licenseType === null) {
                $missing = $payload['missing_slots'] ?? [];
                if (! in_array('license_type', $missing, true)) {
                    $missing[] = 'license_type';
                }
                $payload['missing_slots'] = array_values(array_unique($missing));
                $payload['proposed_action'] = null;
                $payload['requires_confirmation'] = false;
            } elseif (empty($payload['missing_slots']) && empty($payload['proposed_action'])) {
                $payload['proposed_action'] = [
                    'name' => 'create_application',
                    'arguments' => [
                        'license_type_code' => $licenseType,
                        'service_type_code' => $state['service_type_code'] ?? 'new_license',
                    ],
                ];
                $payload['requires_confirmation'] = true;

                $payload = $this->profileGuard->blockCreateApplicationIfProfileNotApproved($citizen, $payload);
                $payload = $this->duplicateGuard->blockCreateApplicationIfDuplicate(
                    $citizen,
                    $payload,
                    $licenseType,
                    (string) ($state['service_type_code'] ?? 'new_license')
                );
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistSessionContext(
        AIAgentSession $session,
        array $payload,
        array $state,
        ?AIAgentAction $pendingAction = null,
    ): void {
        $session->context = $this->sessionContext->buildPersistedContext($session, $payload, $state, $pendingAction);
    }
}
