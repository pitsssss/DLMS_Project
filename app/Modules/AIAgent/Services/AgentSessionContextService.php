<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentMessageIntentMatcher;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;

class AgentSessionContextService
{
    public function __construct(
        private readonly AgentDuplicateApplicationGuard $duplicateGuard,
        private readonly AgentProfileApprovalGuard $profileGuard,
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

        if (AgentWorkflowPhraseMatcher::isWorkflowQuery(
            $userMessage,
            $state['intent'],
            $this->resolveLastDiscussedApplicationId($session)
        )) {
            $state['extracted_license_type'] = null;

            return $state;
        }

        $allowExtract = AgentMessageIntentMatcher::shouldExtractLicenseTypeSlot(
            $userMessage,
            $state['intent'],
            $state['missing_slots']
        );

        $extracted = LicenseTypeSlotExtractor::extract($userMessage, $allowExtract);

        if ($extracted !== null) {
            $state['collected_slots']['license_type_code'] = $extracted;
        }

        $state['extracted_license_type'] = $extracted;

        return $state;
    }

    public function resolveLastDiscussedApplicationId(AIAgentSession $session): ?int
    {
        $context = $session->context ?? [];
        if (isset($context['last_application_id']) && is_numeric($context['last_application_id'])) {
            return (int) $context['last_application_id'];
        }

        $bookAction = AIAgentAction::query()
            ->where('session_id', $session->id)
            ->where('status', AgentActionStatus::Executed)
            ->where('action_name', 'book_appointment')
            ->orderByDesc('id')
            ->first();

        if ($bookAction !== null) {
            $fromBookResult = $this->extractApplicationIdFromArray(
                is_array($bookAction->result) ? $bookAction->result : []
            );
            if ($fromBookResult !== null) {
                return $fromBookResult;
            }
        }

        $actions = AIAgentAction::query()
            ->where('session_id', $session->id)
            ->where('status', AgentActionStatus::Executed)
            ->whereIn('action_name', [
                'get_application_status',
                'get_application_next_step',
                'get_required_documents',
                'create_application',
                'get_available_tests',
                'get_appointment_slots',
                'get_current_appointments',
                'book_appointment',
                'start_payment',
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($actions as $action) {
            $fromResult = $this->extractApplicationIdFromArray(is_array($action->result) ? $action->result : []);
            if ($fromResult !== null) {
                return $fromResult;
            }

            $fromArgs = $this->extractApplicationIdFromArray(is_array($action->arguments) ? $action->arguments : []);
            if ($fromArgs !== null) {
                return $fromArgs;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractApplicationIdFromArray(array $data): ?int
    {
        foreach (['application_id', 'id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    public function isNewLicenseContinuation(array $state, ?string $extractedLicenseType = null): bool
    {
        if (in_array($state['intent'] ?? null, [
            AgentIntent::GetApplicationStatus->value,
            AgentIntent::GetApplicationNextStep->value,
            AgentIntent::GetRequiredDocuments->value,
            AgentIntent::GetApplicationFee->value,
            AgentIntent::StartPayment->value,
            AgentIntent::GetFines->value,
            AgentIntent::GetLicenses->value,
            AgentIntent::GetProfileStatus->value,
            AgentIntent::GetAvailableTests->value,
            AgentIntent::GetAppointmentSlots->value,
            AgentIntent::GetCurrentAppointments->value,
            AgentIntent::BookAppointment->value,
            AgentIntent::GetTestResults->value,
        ], true)) {
            return false;
        }

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
        if (in_array($payload['intent'] ?? null, [
            AgentIntent::GetApplicationStatus->value,
            AgentIntent::GetApplicationNextStep->value,
            AgentIntent::GetRequiredDocuments->value,
            AgentIntent::GetApplicationFee->value,
            AgentIntent::StartPayment->value,
            AgentIntent::GetFines->value,
            AgentIntent::GetLicenses->value,
            AgentIntent::GetProfileStatus->value,
            AgentIntent::GetAvailableTests->value,
            AgentIntent::GetAppointmentSlots->value,
            AgentIntent::GetCurrentAppointments->value,
            AgentIntent::BookAppointment->value,
            AgentIntent::GetTestResults->value,
        ], true)) {
            $state['collected_slots'] = [];
            $state['missing_slots'] = array_values($payload['missing_slots'] ?? []);
            $state['intent'] = $payload['intent'];

            return $state;
        }

        $allowExtract = AgentMessageIntentMatcher::shouldExtractLicenseTypeSlot(
            $userMessage,
            $payload['intent'] ?? $state['intent'],
            $state['missing_slots'] ?? []
        );

        $extracted = LicenseTypeSlotExtractor::extract($userMessage, $allowExtract);

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
        if (AgentWorkflowPhraseMatcher::isWorkflowQuery(
            $userMessage,
            $session->current_intent,
            $this->resolveLastDiscussedApplicationId($session)
        )) {
            return $payload;
        }

        $language = in_array($payload['language'] ?? null, ['ar', 'en'], true)
            ? $payload['language']
            : 'ar';

        $allowExtract = AgentMessageIntentMatcher::shouldExtractLicenseTypeSlot(
            $userMessage,
            $state['intent'] ?? ($payload['intent'] ?? null),
            $state['missing_slots'] ?? []
        );

        $licenseType = $state['collected_slots']['license_type_code']
            ?? $state['extracted_license_type']
            ?? LicenseTypeSlotExtractor::extract($userMessage, $allowExtract);

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

        $payload = $this->profileGuard->blockCreateApplicationIfProfileNotApproved($citizen, $payload);
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

        $existing = is_array($session->context) ? $session->context : [];

        $context = [
            'intent' => $payload['intent'] ?? $session->current_intent,
            'missing_slots' => array_values($payload['missing_slots'] ?? []),
            'collected_slots' => $collected,
            'service_type_code' => $state['service_type_code'] ?? 'new_license',
        ];

        // Preserve conversational document-upload workflow state and document breadcrumbs.
        foreach ([
            'document_flow',
            'pending_workflow',
            'active_application_id',
            'last_application_id',
            'last_uploaded_document_id',
            'last_required_document_id',
            'last_appointment_id',
            'last_test_type_code',
        ] as $preserveKey) {
            if (array_key_exists($preserveKey, $existing)) {
                $context[$preserveKey] = $existing[$preserveKey];
            }
        }

        if ($pendingAction !== null) {
            $context['last_proposed_action'] = [
                'id' => $pendingAction->id,
                'name' => $pendingAction->action_name,
                'status' => $pendingAction->status->value,
                'arguments' => $pendingAction->arguments ?? [],
            ];

            $applicationId = $this->extractApplicationIdFromArray($pendingAction->arguments ?? []);
            if ($applicationId !== null) {
                $context['last_application_id'] = $applicationId;
            }
        }

        $proposedId = $this->extractApplicationIdFromArray(
            is_array($payload['proposed_action']['arguments'] ?? null)
                ? $payload['proposed_action']['arguments']
                : []
        );
        if ($proposedId !== null) {
            $context['last_application_id'] = $proposedId;
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
