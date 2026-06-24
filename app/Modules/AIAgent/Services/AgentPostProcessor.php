<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentTranslator;

class AgentPostProcessor
{
    public function __construct(
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
        private readonly AgentProfileApprovalGuard $profileGuard,
    ) {}

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

        $payload = [
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

        if ($proposedAction !== null
            && AgentSafetyRules::isReadOnlyAction($proposedAction['name'])
            && $missingSlots === []
            && ($raw['execute_immediately'] ?? true) !== false) {
            $payload['requires_confirmation'] = false;
        }

        return AgentTranslator::localizePayload($payload);
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

    /**
     * Ensure the assistant reply matches a pending action that requires confirmation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enforceProfileApprovalRules(User $citizen, array $payload): array
    {
        return $this->profileGuard->blockCreateApplicationIfProfileNotApproved($citizen, $payload);
    }

    public function enforceDuplicateApplicationRules(User $citizen, array $payload): array
    {
        $proposed = $payload['proposed_action'] ?? null;

        if (! is_array($proposed) || ($proposed['name'] ?? '') !== 'create_application') {
            return $payload;
        }

        $arguments = is_array($proposed['arguments'] ?? null) ? $proposed['arguments'] : [];
        $licenseTypeCode = trim((string) ($arguments['license_type_code'] ?? ''));

        if ($licenseTypeCode === '') {
            return $payload;
        }

        $serviceTypeCode = trim((string) ($arguments['service_type_code'] ?? 'new_license'));

        return $this->duplicateGuard->blockCreateApplicationIfDuplicate(
            $citizen,
            $payload,
            $licenseTypeCode,
            $serviceTypeCode
        );
    }

    public function applyConfirmationReply(array $payload): array
    {
        $payload = AgentTranslator::localizePayload($payload);

        if (! ($payload['requires_confirmation'] ?? false)) {
            return $payload;
        }

        $proposed = $payload['proposed_action'] ?? null;
        if (! is_array($proposed) || empty($proposed['name'])) {
            return $payload;
        }

        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        $actionName = (string) $proposed['name'];
        $arguments = is_array($proposed['arguments'] ?? null) ? $proposed['arguments'] : [];

        if ($actionName === 'create_application') {
            $licenseTypeCode = (string) ($arguments['license_type_code'] ?? '');
            $payload['reply'] = $this->createApplicationConfirmationReply($licenseTypeCode, $language);

            return $payload;
        }

        $existingReply = trim((string) ($payload['reply'] ?? ''));
        if ($existingReply !== '' && ! $this->isGenericConfirmationPlaceholder($existingReply)) {
            return $payload;
        }

        $payload['reply'] = $language === 'ar'
            ? 'سيتم تجهيز الإجراء المطلوب. هل تؤكد المتابعة؟'
            : 'I will prepare the requested action. Do you want to continue?';

        return $payload;
    }

    private function isGenericConfirmationPlaceholder(string $reply): bool
    {
        $placeholders = [
            'هل تؤكد؟',
            'هل تؤكد المتابعة؟',
            'Do you confirm?',
            'Do you want to continue?',
        ];

        foreach ($placeholders as $placeholder) {
            if (mb_strtolower($reply) === mb_strtolower($placeholder)) {
                return true;
            }
        }

        return false;
    }

    private function createApplicationConfirmationReply(string $licenseTypeCode, string $language): string
    {
        if ($language === 'en') {
            $label = match ($licenseTypeCode) {
                'private' => 'private driving license',
                'public' => 'public driving license',
                'truck' => 'truck driving license',
                'bus' => 'bus driving license',
                default => 'driving license',
            };

            return "I will prepare a new {$label} application. Do you want to continue?";
        }

        $label = match ($licenseTypeCode) {
            'private' => 'رخصة قيادة خاصة',
            'public' => 'رخصة قيادة عامة',
            'truck' => 'رخصة قيادة شاحنة',
            'bus' => 'رخصة قيادة حافلة',
            default => 'رخصة قيادة',
        };

        return "سيتم تجهيز طلب إصدار {$label}. هل تؤكد المتابعة؟";
    }
}
