<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentMessageIntentMatcher;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentIntentDetector
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
        private readonly AgentApplicationStatusHandler $applicationStatusHandler,
        private readonly AgentApplicationNextStepService $applicationNextStepService,
        private readonly AgentRequiredDocumentsHandler $requiredDocumentsHandler,
    ) {}

    /**
     * Rule-based fallback when Gemini is unavailable or returns invalid JSON.
     *
     * @return array<string, mixed>
     */
    public function detectFallback(User $citizen, string $message, AIAgentSession $session, ?string $languageHint = null): array
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

        if (AgentMessageIntentMatcher::isApplicationStatusQuery($message)) {
            return $this->applicationStatusHandler->buildPayload($citizen, $language);
        }

        if (AgentMessageIntentMatcher::isApplicationNextStepQuery(
            $message,
            $session->current_intent,
            $this->sessionContext->resolveLastDiscussedApplicationId($session)
        )) {
            return $this->applicationNextStepService->buildPayload($citizen, $session, $language);
        }

        if (AgentMessageIntentMatcher::isRequiredDocumentsQuery($message)) {
            return $this->requiredDocumentsHandler->buildPayload($citizen, $session, $language);
        }

        if ($this->sessionContext->isNewLicenseContinuation($state, $state['extracted_license_type'] ?? null)) {
            return $this->sessionContext->applyContinuity(
                $citizen,
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
                    'reply' => $language === 'ar'
                        ? 'يمكنني مساعدتك في إنشاء طلب رخصة جديدة. ما نوع الرخصة التي تريدها؟ خاصة، عامة، شاحنة، أم حافلة؟'
                        : 'I can help you prepare a new license application. Which license type do you need: private, public, truck, or bus?',
                    'missing_slots' => ['license_type'],
                    'proposed_action' => null,
                    'requires_confirmation' => false,
                    'safety_status' => 'safe',
                    'requires_human_support' => false,
                ];
            }

            return $this->sessionContext->applyContinuity(
                $citizen,
                $session,
                $this->generalHelpShape($language),
                $state,
                $message
            );
        }

        return $this->generalHelpShape($language);
    }

    /**
     * Deterministic overrides after Gemini normalization (intent switch, translation keys).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyDeterministicOverrides(
        User $citizen,
        AIAgentSession $session,
        string $userMessage,
        array $payload,
        array $state,
    ): array {
        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        if (AgentMessageIntentMatcher::isApplicationStatusQuery($userMessage)) {
            return $this->applicationStatusHandler->buildPayload($citizen, $language);
        }

        if (AgentMessageIntentMatcher::isApplicationNextStepQuery(
            $userMessage,
            $state['intent'] ?? $session->current_intent,
            $this->sessionContext->resolveLastDiscussedApplicationId($session)
        )) {
            return $this->applicationNextStepService->buildPayload($citizen, $session, $language);
        }

        if (AgentMessageIntentMatcher::isRequiredDocumentsQuery($userMessage)) {
            return $this->requiredDocumentsHandler->buildPayload($citizen, $session, $language);
        }

        $payload = AgentTranslator::localizePayload($payload);

        return $payload;
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
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة. يمكنني مساعدتك في طلب رخصة جديدة، متابعة الطلب، المستندات، الدفع، المواعيد، النتائج، الرخص، والمخالفات. كيف يمكنني مساعدتك؟'
                : 'I assist with driving license services only. I can help with new applications, status, documents, payments, appointments, results, licenses, and fines. How can I help?',
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
            'reply' => $language === 'ar'
                ? 'هذا الإجراء يتطلب موظفاً مخولاً. لا يمكنني تنفيذه نيابة عنك.'
                : 'This action requires an authorized employee. I cannot perform it for you.',
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
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة فقط. يرجى طرح سؤال متعلق بالرخصة أو الطلب أو المواعيد أو المستندات.'
                : 'I only support driving license services. Please ask about licenses, applications, appointments, or documents.',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ];
    }
}
