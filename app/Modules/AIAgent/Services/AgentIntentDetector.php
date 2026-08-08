<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentTranslator;

class AgentIntentDetector
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
        private readonly AgentWorkflowOrchestrator $workflowOrchestrator,
    ) {}

    /**
     * Rule-based fallback when Gemini is unavailable or returns invalid JSON.
     *
     * @return array<string, mixed>
     */
    public function detectFallback(User $citizen, string $message, AIAgentSession $session, ?string $languageHint = null): array
    {
        $language = $languageHint ?? 'ar';
        $state = $this->sessionContext->mergeUserMessage($session, $message);

        $payload = $this->workflowOrchestrator->resolveDeterministicPayload(
            $citizen,
            $message,
            $session,
            $language,
            $state
        );

        if ($payload !== null) {
            return $payload;
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
            : AgentTranslator::getLocale();

        // Prefer the request-resolved session locale over Gemini's language guess.
        $resolved = AgentTranslator::getLocale();
        if (in_array($resolved, ['ar', 'en'], true)) {
            $language = $resolved;
        }

        $override = $this->workflowOrchestrator->overridePayload(
            $citizen,
            $userMessage,
            $session,
            $language,
            $state
        );

        if ($override !== null) {
            return $override;
        }

        return AgentTranslator::localizePayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function generalHelpShape(string $language): array
    {
        return [
            'intent' => \App\Modules\AIAgent\Enums\AgentIntent::GeneralHelp->value,
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
}
