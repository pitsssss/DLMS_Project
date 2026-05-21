<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentSafetyRules;

class AgentPostProcessor
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    public function normalize(?array $raw, string $userMessage, ?string $languageHint = null): ?array
    {
        if ($raw === null) {
            return null;
        }

        $intent = (string) ($raw['intent'] ?? AgentIntent::Unknown->value);
        $confidence = $this->normalizeConfidence($raw['confidence'] ?? null);
        $language = in_array($raw['language'] ?? null, ['ar', 'en'], true)
            ? $raw['language']
            : ($languageHint ?? 'ar');
        $reply = trim((string) ($raw['reply'] ?? ''));
        $missingSlots = $this->normalizeStringList($raw['missing_slots'] ?? []);
        $requiresConfirmation = (bool) ($raw['requires_confirmation'] ?? false);
        $safetyStatus = in_array($raw['safety_status'] ?? null, ['safe', 'blocked'], true)
            ? $raw['safety_status']
            : 'safe';
        $requiresHumanSupport = (bool) ($raw['requires_human_support'] ?? false);
        $proposedAction = $this->normalizeProposedAction($raw['proposed_action'] ?? null);

        if (! in_array($intent, AgentSafetyRules::validIntentValues(), true)) {
            $intent = AgentIntent::Unknown->value;
            $confidence = min($confidence, 0.4);
        }

        if (AgentSafetyRules::messageLooksAdminRelated($userMessage)
            || in_array($intent, AgentSafetyRules::ADMIN_INTENTS, true)
            || ($proposedAction !== null && AgentSafetyRules::isAdminOnlyAction($proposedAction['name']))) {
            return [
                'intent' => AgentIntent::AdminActionDenied->value,
                'confidence' => max($confidence, 0.9),
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

        if ($proposedAction !== null && ! AgentSafetyRules::isAllowedProposedAction($proposedAction['name'])) {
            $proposedAction = null;
            $requiresConfirmation = false;
            $confidence = min($confidence, 0.5);
        }

        if ($reply === '') {
            return null;
        }

        $threshold = (float) config('ai.agent.low_confidence_threshold', 0.55);
        if ($confidence < $threshold && $intent !== AgentIntent::OutOfScope->value) {
            $requiresHumanSupport = true;
            if ($missingSlots === []) {
                $missingSlots = ['clarification'];
            }
            $proposedAction = null;
            $requiresConfirmation = false;
        }

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'language' => $language,
            'reply' => $reply,
            'missing_slots' => $missingSlots,
            'proposed_action' => $proposedAction,
            'requires_confirmation' => $requiresConfirmation,
            'safety_status' => $safetyStatus,
            'requires_human_support' => $requiresHumanSupport,
        ];
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.5;
        }

        $confidence = (float) $value;

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : null,
            $value
        )));
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    private function normalizeProposedAction(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $name = trim((string) ($value['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $arguments = is_array($value['arguments'] ?? null) ? $value['arguments'] : [];

        return [
            'name' => $name,
            'arguments' => $arguments,
        ];
    }
}
