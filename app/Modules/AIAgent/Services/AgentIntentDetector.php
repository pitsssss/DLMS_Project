<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentIntentDetector
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
    ) {}

    /**
     * Rule-based fallback when Gemini is unavailable or returns invalid JSON.
     *
     * @return array<string, mixed>
     */
    public function detectFallback(string $message, AIAgentSession $session, ?string $languageHint = null): array
    {
        $language = $languageHint ?? 'ar';
        $normalized = mb_strtolower(trim($message));
        $state = $this->sessionContext->mergeUserMessage($session, $message);

        if (AgentSafetyRules::messageLooksAdminRelated($message)) {
            return $this->adminDeniedResponse($language);
        }

        if ($this->isOutOfScope($normalized)) {
            return $this->outOfScopeResponse($language);
        }

        if ($this->sessionContext->isNewLicenseContinuation($state, $state['extracted_license_type'] ?? null)) {
            return $this->sessionContext->applyContinuity(
                $session,
                $this->generalHelpShape($language),
                $state,
                $message
            );
        }

        if ($this->matchesNewLicenseIntent($normalized)) {
            $licenseType = $state['collected_slots']['license_type_code'] ?? null;

            if ($licenseType === null) {
                return [
                    'intent' => AgentIntent::CreateNewLicenseApplication->value,
                    'confidence' => 0.72,
                    'language' => $language,
                    'reply' => __('messages.ai_agent.new_license_prompt'),
                    'missing_slots' => ['license_type'],
                    'proposed_action' => null,
                    'requires_confirmation' => false,
                    'safety_status' => 'safe',
                    'requires_human_support' => false,
                ];
            }

            return $this->sessionContext->applyContinuity(
                $session,
                $this->generalHelpShape($language),
                $state,
                $message
            );
        }

        return $this->generalHelpShape($language);
    }

    /**
     * @return array<string, mixed>
     */
    private function generalHelpShape(string $language): array
    {
        return [
            'intent' => AgentIntent::GeneralHelp->value,
            'confidence' => 0.45,
            'language' => $language,
            'reply' => __('messages.ai_agent.general_help'),
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ];
    }

    private function matchesNewLicenseIntent(string $normalized): bool
    {
        $patterns = [
            'رخصة جديدة',
            'رخصه جديده',
            'بدي رخصة',
            'بدي رخصه',
            'new license',
            'new driving license',
            'apply for license',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isOutOfScope(string $normalized): bool
    {
        $patterns = [
            'weather',
            'football',
            'bitcoin',
            'recipe',
            'الطقس',
            'كرة القدم',
            'طبخ',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeniedResponse(string $language): array
    {
        return [
            'intent' => AgentIntent::AdminActionDenied->value,
            'confidence' => 0.95,
            'language' => $language,
            'reply' => __('messages.ai_agent.admin_denied'),
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'blocked',
            'requires_human_support' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outOfScopeResponse(string $language): array
    {
        return [
            'intent' => AgentIntent::OutOfScope->value,
            'confidence' => 0.9,
            'language' => $language,
            'reply' => __('messages.ai_agent.out_of_scope'),
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ];
    }
}
