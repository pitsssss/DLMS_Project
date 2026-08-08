<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Enums\DocumentFlowState;
use App\Modules\AIAgent\Enums\PendingWorkflowInspectionStatus;
use App\Modules\AIAgent\Enums\PendingWorkflowState;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentActionArgumentValidator;
use App\Modules\AIAgent\Support\AgentApplicationStatusMap;
use App\Modules\AIAgent\Support\AgentApplicationTextSelector;
use App\Modules\AIAgent\Support\AgentWorkflowIntentCatalog;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgentPendingWorkflowService
{
    public function __construct(
        private readonly AgentSelectionTokenService $selectionTokens,
        private readonly AgentApplicationActionPolicy $policy,
        private readonly AgentApplicationStatusHandler $statusHandler,
        private readonly AgentApplicationNextStepService $nextStepService,
        private readonly AgentRequiredDocumentsHandler $requiredDocumentsHandler,
        private readonly AgentWorkflowOrchestrator $orchestrator,
        private readonly AIAgentActionService $actionService,
    ) {}

    public function getWorkflow(AIAgentSession $session): ?array
    {
        $workflow = $session->context['pending_workflow'] ?? null;

        return is_array($workflow) ? $workflow : null;
    }

    /**
     * Inspect pending workflow without clearing it.
     *
     * @return array{status: PendingWorkflowInspectionStatus, workflow: ?array}
     */
    public function inspect(AIAgentSession $session): array
    {
        $workflow = $this->getWorkflow($session);
        if ($workflow === null) {
            return ['status' => PendingWorkflowInspectionStatus::None, 'workflow' => null];
        }

        if ($this->isExpired($workflow)) {
            return ['status' => PendingWorkflowInspectionStatus::Expired, 'workflow' => $workflow];
        }

        $state = (string) ($workflow['state'] ?? '');
        if (in_array($state, [
            PendingWorkflowState::AwaitingApplicationChoice->value,
            PendingWorkflowState::AwaitingAppointmentSlotChoice->value,
            PendingWorkflowState::Failed->value,
            PendingWorkflowState::Resuming->value,
        ], true)) {
            return ['status' => PendingWorkflowInspectionStatus::Active, 'workflow' => $workflow];
        }

        return ['status' => PendingWorkflowInspectionStatus::InvalidState, 'workflow' => $workflow];
    }

    public function isAwaitingApplicationChoice(AIAgentSession $session): bool
    {
        $inspection = $this->inspect($session);
        if ($inspection['status'] !== PendingWorkflowInspectionStatus::Active) {
            return false;
        }

        $state = (string) (($inspection['workflow']['state'] ?? ''));

        return in_array($state, [
            PendingWorkflowState::AwaitingApplicationChoice->value,
            PendingWorkflowState::Failed->value,
        ], true);
    }

    public function shouldHandlePendingMessage(AIAgentSession $session): bool
    {
        $inspection = $this->inspect($session);

        return in_array($inspection['status'], [
            PendingWorkflowInspectionStatus::Active,
            PendingWorkflowInspectionStatus::Expired,
        ], true);
    }

    /**
     * Soft expired response for chat / show-again. Clears workflow after capturing intent.
     *
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    public function respondExpired(AIAgentSession $session, array $workflow): array
    {
        $intent = (string) ($workflow['intent'] ?? 'unknown');
        $this->clear($session);

        return [
            'session_id' => $session->id,
            'message_type' => 'application_selection_expired',
            'reply' => 'انتهت صلاحية عملية اختيار الطلب. يرجى إعادة طلب الخدمة.',
            'intent' => $intent,
            'confidence' => 1.0,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'pending_action' => null,
            'ui_payload' => null,
        ];
    }

    /**
     * @throws ApiException
     */
    public function assertActiveForInteraction(AIAgentSession $session): array
    {
        $inspection = $this->inspect($session);

        if ($inspection['status'] === PendingWorkflowInspectionStatus::Expired) {
            $this->clear($session);
            throw new ApiException(
                'انتهت صلاحية عملية اختيار الطلب. يرجى إعادة طلب الخدمة.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_EXPIRED'
            );
        }

        if ($inspection['status'] !== PendingWorkflowInspectionStatus::Active || $inspection['workflow'] === null) {
            throw new ApiException(
                'لا توجد عملية اختيار طلب قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        return $inspection['workflow'];
    }

    /**
     * Enrich multi-application payloads with pending_workflow + selection buttons.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichPayloadIfNeeded(User $citizen, AIAgentSession $session, array $payload, string $originalMessage = ''): array
    {
        $missing = $payload['missing_slots'] ?? [];
        if (! is_array($missing) || ! in_array('application_choice', $missing, true)) {
            return $payload;
        }

        // Document flow owns its own application selection UI.
        if ($this->documentFlowOwnsApplicationSelection($session)) {
            return $payload;
        }

        $intent = (string) ($payload['intent'] ?? '');
        if ($intent === '' || ! AgentWorkflowIntentCatalog::requiresApplication($intent)) {
            return $payload;
        }

        $candidates = $this->resolveCandidates($citizen, $intent);
        if ($candidates->count() <= 1) {
            return $payload;
        }

        $workflow = $this->createAwaitingWorkflow($session, $intent, $originalMessage, $candidates, $payload);

        return $this->selectionRequiredResponse($citizen, $session, $workflow, $candidates, $payload);
    }

    /**
     * Handle chat while a pending workflow is active or expired.
     * Returns null only for clear topic-change (caller continues with new intent).
     *
     * @return array<string, mixed>|null
     */
    public function handleAwaitingMessage(User $citizen, AIAgentSession $session, string $message): ?array
    {
        $inspection = $this->inspect($session);

        if ($inspection['status'] === PendingWorkflowInspectionStatus::Expired) {
            return $this->respondExpired($session, $inspection['workflow'] ?? []);
        }

        if ($inspection['status'] !== PendingWorkflowInspectionStatus::Active || $inspection['workflow'] === null) {
            return null;
        }

        $workflow = $inspection['workflow'];
        $state = (string) ($workflow['state'] ?? '');

        // 1) Explicit new intent / topic change first.
        if (AgentWorkflowPhraseMatcher::isWorkflowQuery(
            $message,
            (string) ($workflow['intent'] ?? null),
            null
        )) {
            $this->clear($session);

            return null;
        }

        // 2) Exact cancellation only.
        if (AgentApplicationTextSelector::isCancelPhrase($message)) {
            return $this->cancel($session, $workflow);
        }

        // Slot-choice stage: do not fall through to Gemini/general_help.
        if ($state === PendingWorkflowState::AwaitingAppointmentSlotChoice->value) {
            return $this->appointmentSlotSelectionRequiredResponse(
                $citizen,
                $session,
                $workflow,
                (int) ($workflow['collected_slots']['application_id'] ?? 0)
            );
        }

        if (! in_array($state, [
            PendingWorkflowState::AwaitingApplicationChoice->value,
            PendingWorkflowState::Failed->value,
        ], true)) {
            return null;
        }

        // Restore failed → awaiting for retry attempts.
        if ($state === PendingWorkflowState::Failed->value) {
            $workflow['state'] = PendingWorkflowState::AwaitingApplicationChoice->value;
            $this->writeWorkflow($session, $workflow);
        }

        $candidates = $this->loadCandidatesByIds($citizen, $workflow['candidate_application_ids'] ?? []);
        $resolution = AgentApplicationTextSelector::resolve($message, $candidates);

        if (($resolution['status'] ?? '') === 'matched' && isset($resolution['application_id'])) {
            return $this->resumeWithApplication($citizen, $session, (int) $resolution['application_id']);
        }

        if (($resolution['status'] ?? '') === 'ambiguous' && ! empty($resolution['matched_ids'])) {
            $narrowed = $candidates->whereIn('id', $resolution['matched_ids'])->values();
            $workflow['candidate_application_ids'] = $narrowed->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->writeWorkflow($session, $workflow);

            return $this->selectionRequiredResponse(
                $citizen,
                $session,
                $workflow,
                $narrowed,
                [
                    'intent' => $workflow['intent'],
                    'reply' => 'وجدت أكثر من طلب مطابق. يرجى اختيار الطلب المقصود بدقة.',
                    'missing_slots' => ['application_choice'],
                    'confidence' => 1.0,
                    'requires_confirmation' => false,
                    'pending_action' => null,
                ]
            );
        }

        return $this->selectionRequiredResponse(
            $citizen,
            $session,
            $workflow,
            $candidates,
            [
                'intent' => $workflow['intent'],
                'reply' => 'لم أتمكن من تحديد الطلب المقصود. يرجى اختيار أحد الطلبات المعروضة.',
                'missing_slots' => ['application_choice'],
                'confidence' => 1.0,
                'requires_confirmation' => false,
                'pending_action' => null,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function selectApplicationByToken(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        if ($this->documentFlowOwnsApplicationSelection($session)) {
            throw new ApiException(
                'اختيار الطلب غير متاح عبر هذا المسار حاليًا.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $workflow = $this->assertActiveForInteraction($session);

        $state = (string) ($workflow['state'] ?? '');
        if (! in_array($state, [
            PendingWorkflowState::AwaitingApplicationChoice->value,
            PendingWorkflowState::Failed->value,
        ], true)) {
            throw new ApiException(
                'حالة عملية اختيار الطلب غير صالحة لهذا الإجراء.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        if ($state === PendingWorkflowState::Failed->value) {
            $workflow['state'] = PendingWorkflowState::AwaitingApplicationChoice->value;
            $this->writeWorkflow($session, $workflow);
        }

        $verified = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_PENDING_APPLICATION,
            (string) ($workflow['workflow_id'] ?? ''),
            (string) ($workflow['intent'] ?? '')
        );

        return $this->resumeWithApplication($citizen, $session, (int) $verified['aid']);
    }

    /**
     * Re-issue application selection buttons for the current pending workflow.
     *
     * @return array<string, mixed>
     */
    public function showChoicesAgain(User $citizen, AIAgentSession $session): array
    {
        $inspection = $this->inspect($session);

        if ($inspection['status'] === PendingWorkflowInspectionStatus::Expired) {
            return $this->respondExpired($session, $inspection['workflow'] ?? []);
        }

        $workflow = $this->assertActiveForInteraction($session);

        $candidates = $this->loadCandidatesByIds($citizen, $workflow['candidate_application_ids'] ?? []);
        if ($candidates->isEmpty()) {
            $this->clear($session);
            throw new ApiException(
                'لم تعد الطلبات المعروضة متاحة. يرجى إعادة طلب الخدمة.',
                422,
                [],
                [],
                'APPLICATION_NO_LONGER_ELIGIBLE'
            );
        }

        $workflow['state'] = PendingWorkflowState::AwaitingApplicationChoice->value;
        $this->writeWorkflow($session, $workflow);

        return $this->selectionRequiredResponse(
            $citizen,
            $session,
            $workflow,
            $candidates,
            [
                'intent' => $workflow['intent'] ?? '',
                'reply' => 'يرجى اختيار أحد الطلبات المعروضة.',
                'missing_slots' => ['application_choice'],
                'confidence' => 1.0,
                'requires_confirmation' => false,
                'pending_action' => null,
            ]
        );
    }

    public function cancelPending(User $citizen, AIAgentSession $session): array
    {
        $workflow = $this->getWorkflow($session);
        if ($workflow === null) {
            throw new ApiException(
                'لا توجد عملية اختيار طلب قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        if ($this->isExpired($workflow)) {
            return $this->respondExpired($session, $workflow);
        }

        return $this->cancel($session, $workflow);
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeWithApplication(User $citizen, AIAgentSession $session, int $applicationId): array
    {
        $workflow = $this->getWorkflow($session);
        if ($workflow === null) {
            throw new ApiException(
                'لا توجد عملية اختيار طلب قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        if ($this->isExpired($workflow)) {
            $this->clear($session);
            throw new ApiException(
                'انتهت صلاحية عملية اختيار الطلب. يرجى إعادة طلب الخدمة.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_EXPIRED'
            );
        }

        $state = (string) ($workflow['state'] ?? '');
        if (! in_array($state, [
            PendingWorkflowState::AwaitingApplicationChoice->value,
            PendingWorkflowState::Failed->value,
            PendingWorkflowState::Resuming->value,
        ], true)) {
            throw new ApiException(
                'لا توجد عملية اختيار طلب قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        $candidateIds = array_map('intval', $workflow['candidate_application_ids'] ?? []);
        if (! in_array($applicationId, $candidateIds, true)) {
            throw new ApiException(
                'الطلب المحدد غير موجود ضمن الخيارات المعروضة.',
                422,
                [],
                [],
                'APPLICATION_SELECTION_INVALID'
            );
        }

        $intent = (string) ($workflow['intent'] ?? '');
        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($applicationId)
            ->with(['licenseType', 'serviceType'])
            ->first();

        if ($application === null) {
            $this->clear($session);
            throw new ApiException(
                'الطلب غير موجود أو لا تملك صلاحية الوصول إليه.',
                404,
                [],
                [],
                'APPLICATION_NOT_OWNED'
            );
        }

        if (! $this->isEligibleForIntent($application, $intent)) {
            $this->clear($session);
            throw new ApiException(
                'الطلب المحدد لم يعد مؤهلاً لهذه العملية.',
                422,
                [],
                [],
                'APPLICATION_NO_LONGER_ELIGIBLE'
            );
        }

        $workflow['state'] = PendingWorkflowState::Resuming->value;
        $this->writeWorkflow($session, $workflow);

        $context = $session->context ?? [];
        $context['last_application_id'] = $applicationId;
        $context['active_application_id'] = $applicationId;
        $session->context = $context;
        $session->current_intent = $intent;
        $session->last_message_at = now();
        $session->save();

        try {
            $result = $this->continueIntent($citizen, $session, $intent, $application);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $this->isTerminalApiException($e)) {
                $this->clear($session);
                throw $e;
            }

            $workflow = $this->getWorkflow($session) ?? $workflow;
            $workflow['state'] = PendingWorkflowState::AwaitingApplicationChoice->value;
            $metadata = is_array($workflow['metadata'] ?? null) ? $workflow['metadata'] : [];
            $metadata['last_error_code'] = 'PENDING_WORKFLOW_RESUME_FAILED';
            $metadata['retryable'] = true;
            $metadata['failed_at'] = now()->toIso8601String();
            $workflow['metadata'] = $metadata;
            $this->writeWorkflow($session, $workflow);

            if ($e instanceof ApiException && $e->getErrorCode() === 'PENDING_WORKFLOW_RETRY_REQUIRED') {
                throw $e;
            }

            throw new ApiException(
                'تعذر استكمال العملية مؤقتًا. يرجى إعادة اختيار الطلب.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_RETRY_REQUIRED'
            );
        }

        // Incomplete multi-slot flows keep pending_workflow (e.g. appointment slot).
        if (($result['keep_pending_workflow'] ?? false) === true) {
            unset($result['keep_pending_workflow']);

            return $result;
        }

        $this->clear($session);

        $context = $session->context ?? [];
        $context['last_application_id'] = $applicationId;
        $session->context = $context;
        $session->save();

        return $result;
    }

    private function isTerminalApiException(ApiException $e): bool
    {
        return in_array($e->getErrorCode(), [
            'APPLICATION_NOT_OWNED',
            'APPLICATION_NO_LONGER_ELIGIBLE',
            'APPLICATION_SELECTION_INVALID',
            'PENDING_WORKFLOW_EXPIRED',
            'APPLICATION_SELECTION_TOKEN_INVALID',
            'APPLICATION_SELECTION_TOKEN_EXPIRED',
            'APPLICATION_SELECTION_TOKEN_MISMATCH',
            'ACTION_ARGUMENTS_INCOMPLETE',
            'PENDING_WORKFLOW_ALREADY_COMPLETED',
        ], true);
    }

    /**
     * @param  Collection<int, LicenseApplication>  $candidates
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createAwaitingWorkflow(
        AIAgentSession $session,
        string $intent,
        string $originalMessage,
        Collection $candidates,
        array $payload,
    ): array {
        $ttl = (int) config('ai.agent.pending_workflow_ttl_seconds', 900);
        $workflow = [
            'workflow_id' => (string) Str::uuid(),
            'state' => PendingWorkflowState::AwaitingApplicationChoice->value,
            'intent' => $intent,
            'original_message' => mb_substr($originalMessage, 0, 500),
            'required_slot' => 'application_choice',
            'required_slots' => ['application_choice'],
            'current_required_slot' => 'application_choice',
            'candidate_application_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'collected_slots' => [],
            'metadata' => [
                'action_name' => AgentWorkflowIntentCatalog::actionName($intent),
            ],
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];

        $this->writeWorkflow($session, $workflow);
        $session->current_intent = $intent;
        $session->save();

        return $workflow;
    }

    /**
     * @param  Collection<int, LicenseApplication>  $candidates
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function selectionRequiredResponse(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        Collection $candidates,
        array $base,
    ): array {
        $intent = (string) ($workflow['intent'] ?? $base['intent'] ?? '');
        $ttl = (int) config('ai.agent.selection_token_ttl_seconds', 1800);
        $applications = $candidates->map(function (LicenseApplication $application) use ($citizen, $session, $workflow, $intent, $ttl): array {
            $serviceCode = (string) ($application->serviceType?->code ?? '');
            $licenseCode = (string) ($application->licenseType?->code ?? '');
            $status = $application->status instanceof ApplicationStatus
                ? $application->status->value
                : (string) $application->status;
            $serviceLabel = (string) ($application->serviceType?->name ?? $serviceCode);
            $licenseLabel = LicenseTypeSlotExtractor::labelAr($licenseCode);
            $statusLabel = ApplicationStatusLabelMapper::labelAr($application->status);
            $reference = (string) ($application->application_number ?: $application->id);

            return [
                'label' => "{$serviceLabel} — {$reference}",
                'subtitle' => "رخصة {$licenseLabel} — {$statusLabel}",
                'service_type' => $serviceCode,
                'service_type_label' => $serviceLabel,
                'license_type' => $licenseCode,
                'license_type_label' => $licenseLabel,
                'status' => $status,
                'status_label' => $statusLabel,
                'selection_token' => $this->selectionTokens->issue(
                    $citizen,
                    $session,
                    AgentSelectionTokenService::PURPOSE_PENDING_APPLICATION,
                    (int) $application->id,
                    null,
                    $ttl,
                    (string) ($workflow['workflow_id'] ?? ''),
                    $intent
                ),
            ];
        })->values()->all();

        $reply = (string) ($base['reply'] ?? 'لديك أكثر من طلب قيد المتابعة. يرجى اختيار الطلب المطلوب.');

        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'application_selection_required',
            'intent' => $intent,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'application_selection_required',
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => (float) ($base['confidence'] ?? 1.0),
            'missing_slots' => ['application_choice'],
            'requires_confirmation' => false,
            'pending_action' => null,
            'ui_payload' => [
                'selection_purpose' => $intent,
                'applications' => $applications,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function continueIntent(
        User $citizen,
        AIAgentSession $session,
        string $intent,
        LicenseApplication $application,
    ): array {
        $language = 'ar';
        $actionName = AgentWorkflowIntentCatalog::actionName($intent);

        // Force session target for handlers that resolve via last_application_id.
        $context = $session->context ?? [];
        $context['last_application_id'] = $application->id;
        $session->context = $context;
        $session->save();

        if ($intent === 'get_application_status') {
            $payload = $this->statusHandler->buildPayloadForApplication($citizen, $application, $language);
        } else {
            $payload = $this->orchestrator->resolveDeterministicPayload(
                $citizen,
                (string) (($this->getWorkflow($session)['original_message'] ?? '') ?: $intent),
                $session,
                $language,
                ['intent' => $intent, 'missing_slots' => [], 'collected_slots' => []]
            );

            // Fallback: build via status-like forced paths when orchestrator re-asks choice.
            if ($payload === null || in_array('application_choice', $payload['missing_slots'] ?? [], true)) {
                $payload = $this->buildForcedPayload($citizen, $session, $intent, $application, $language);
            }
        }

        $payload['intent'] = $intent;
        $payload['confidence'] = 1.0;
        $payload['missing_slots'] = [];
        $session->current_intent = $intent;
        $session->save();

        // book_appointment: never confirm without appointment_slot_id — keep workflow open.
        if ($intent === 'book_appointment' || $actionName === 'book_appointment') {
            return $this->appointmentSlotSelectionRequiredResponse($citizen, $session, $this->getWorkflow($session) ?? [], (int) $application->id);
        }

        if ($actionName !== null && AgentWorkflowIntentCatalog::isReadOnly($intent)) {
            $arguments = ['application_id' => $application->id];
            AgentActionArgumentValidator::assertComplete($actionName, $arguments);

            $action = AIAgentAction::query()->create([
                'session_id' => $session->id,
                'user_id' => $citizen->id,
                'action_name' => $actionName,
                'arguments' => $arguments,
                'status' => AgentActionStatus::Pending,
                'requires_confirmation' => false,
                'confirmation_message' => null,
            ]);

            $executed = $this->actionService->executeReadOnlyNow($citizen, $action->id);
            $reply = (string) ($executed['reply'] ?? $payload['reply'] ?? '');

            return [
                'session_id' => $session->id,
                'message_type' => $this->messageTypeForIntent($intent),
                'reply' => $reply,
                'intent' => $intent,
                'confidence' => 1.0,
                'missing_slots' => [],
                'requires_confirmation' => false,
                'pending_action' => null,
                'executed_action' => $executed['action'] ?? [
                    'id' => $action->id,
                    'name' => $actionName,
                    'arguments' => $arguments,
                    'requires_confirmation' => false,
                    'status' => AgentActionStatus::Executed->value,
                ],
                'result' => $executed['result'] ?? null,
                'action_confirmed' => true,
                'action_cancelled' => false,
                'application' => $this->applicationCard($application),
                'ui_payload' => is_array($payload['ui_payload'] ?? null) ? $payload['ui_payload'] : [],
                'suggested_next_actions' => array_values($executed['suggested_next_actions'] ?? []),
            ];
        }

        // Mutating: propose confirmation action only when arguments are complete.
        $proposed = $payload['proposed_action'] ?? [
            'name' => $actionName,
            'arguments' => ['application_id' => $application->id],
        ];

        if (! is_array($proposed) || empty($proposed['name'])) {
            $proposed = [
                'name' => $actionName,
                'arguments' => ['application_id' => $application->id],
            ];
        }

        $proposed['arguments'] = array_merge(
            is_array($proposed['arguments'] ?? null) ? $proposed['arguments'] : [],
            ['application_id' => $application->id]
        );

        $missingArgs = AgentActionArgumentValidator::missingArguments(
            (string) $proposed['name'],
            is_array($proposed['arguments'] ?? null) ? $proposed['arguments'] : []
        );

        if ($missingArgs !== []) {
            if (in_array('appointment_slot_id', $missingArgs, true)) {
                return $this->appointmentSlotSelectionRequiredResponse(
                    $citizen,
                    $session,
                    $this->getWorkflow($session) ?? [],
                    (int) $application->id
                );
            }

            throw new ApiException(
                'لا يمكن المتابعة قبل اكتمال البيانات المطلوبة.',
                422,
                ['missing_arguments' => $missingArgs],
                [],
                'ACTION_ARGUMENTS_INCOMPLETE',
                ['missing_arguments' => $missingArgs]
            );
        }

        $payload['proposed_action'] = $proposed;
        $payload['requires_confirmation'] = true;
        $payload['execute_immediately'] = false;
        $payload['missing_slots'] = [];

        $action = AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => (string) $proposed['name'],
            'arguments' => $proposed['arguments'],
            'status' => AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => (string) ($payload['reply'] ?? ''),
        ]);

        $reply = (string) ($payload['reply'] ?? 'تم تحديد الطلب. هل تؤكد المتابعة؟');
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'application_selected_confirmation_required',
            'action_id' => $action->id,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'application_selected_confirmation_required',
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => 1.0,
            'missing_slots' => [],
            'requires_confirmation' => true,
            'pending_action' => [
                'id' => $action->id,
                'name' => $action->action_name,
                'arguments' => $action->arguments,
                'requires_confirmation' => true,
                'status' => $action->status->value,
            ],
            'application' => $this->applicationCard($application),
            'ui_payload' => [
                'requires_confirmation' => true,
                'action_name' => $action->action_name,
            ],
        ];
    }

    /**
     * Safe multi-slot guard: keep pending workflow and request appointment slot.
     *
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function appointmentSlotSelectionRequiredResponse(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        int $applicationId,
    ): array {
        if ($applicationId <= 0) {
            throw new ApiException(
                'تعذر تحديد الطلب لإكمال الحجز.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($applicationId)
            ->with(['licenseType', 'serviceType'])
            ->first();

        if ($application === null) {
            $this->clear($session);
            throw new ApiException(
                'الطلب غير موجود أو لا تملك صلاحية الوصول إليه.',
                404,
                [],
                [],
                'APPLICATION_NOT_OWNED'
            );
        }

        $workflow['state'] = PendingWorkflowState::AwaitingAppointmentSlotChoice->value;
        $workflow['required_slots'] = ['application_choice', 'appointment_slot_choice'];
        $workflow['current_required_slot'] = 'appointment_slot_choice';
        $workflow['required_slot'] = 'appointment_slot_choice';
        $collected = is_array($workflow['collected_slots'] ?? null) ? $workflow['collected_slots'] : [];
        $collected['application_id'] = $applicationId;
        $workflow['collected_slots'] = $collected;
        $workflow['intent'] = 'book_appointment';
        $this->writeWorkflow($session, $workflow);

        $session->current_intent = 'book_appointment';
        $session->save();

        $reply = 'يرجى اختيار الموعد المناسب لإكمال الحجز.';
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'appointment_slot_selection_required',
            'intent' => 'book_appointment',
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'appointment_slot_selection_required',
            'reply' => $reply,
            'intent' => 'book_appointment',
            'confidence' => 1.0,
            'missing_slots' => ['appointment_slot_choice'],
            'requires_confirmation' => false,
            'pending_action' => null,
            'application' => $this->applicationCard($application),
            'ui_payload' => [
                'selection_type' => 'appointment_slot',
                'application' => $this->applicationCard($application),
                'slots' => [],
            ],
            'keep_pending_workflow' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForcedPayload(
        User $citizen,
        AIAgentSession $session,
        string $intent,
        LicenseApplication $application,
        string $language,
    ): array {
        return match ($intent) {
            'get_application_status' => $this->statusHandler->buildPayloadForApplication($citizen, $application, $language),
            'get_application_next_step' => [
                'intent' => $intent,
                'reply' => $this->nextStepService->nextStepForApplication($application, $language)['reply'],
                'proposed_action' => [
                    'name' => 'get_application_next_step',
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => false,
                'execute_immediately' => true,
                'missing_slots' => [],
                'confidence' => 1.0,
                'language' => $language,
            ],
            'get_required_documents' => [
                'intent' => $intent,
                'reply' => $this->requiredDocumentsHandler->formatReply(
                    $application,
                    app(\App\Modules\Applications\Services\ApplicationDocumentService::class)
                        ->requiredChecklist($citizen, $application->id)
                ),
                'proposed_action' => [
                    'name' => 'get_required_documents',
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => false,
                'execute_immediately' => true,
                'missing_slots' => [],
                'confidence' => 1.0,
                'language' => $language,
            ],
            default => [
                'intent' => $intent,
                'reply' => 'تم تحديد الطلب. هل تؤكد المتابعة؟',
                'proposed_action' => [
                    'name' => AgentWorkflowIntentCatalog::actionName($intent),
                    'arguments' => ['application_id' => $application->id],
                ],
                'requires_confirmation' => ! AgentWorkflowIntentCatalog::isReadOnly($intent),
                'execute_immediately' => AgentWorkflowIntentCatalog::isReadOnly($intent),
                'missing_slots' => [],
                'confidence' => 1.0,
                'language' => $language,
            ],
        };
    }

    /**
     * @return Collection<int, LicenseApplication>
     */
    public function resolveCandidates(User $citizen, string $intent): Collection
    {
        $action = AgentWorkflowIntentCatalog::actionName($intent) ?? $intent;

        $query = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'serviceType'])
            ->orderByDesc('id');

        // Status / next-step: all active applications.
        if (in_array($intent, ['get_application_status', 'get_application_next_step'], true)) {
            $query->whereIn('status', ApplicationStatus::activeValues());
        } elseif (in_array($intent, ['get_required_documents', 'submit_documents_for_review'], true)) {
            $query->whereIn('status', [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsRejected->value,
            ]);
        } else {
            $query->whereIn('status', ApplicationStatus::activeValues());
        }

        return $query->get()->filter(function (LicenseApplication $application) use ($intent, $action): bool {
            if (in_array($intent, ['get_application_status', 'get_application_next_step'], true)) {
                return true;
            }

            // For fee: allow if fee action not blocked OR status is payment-related / draft reading fee catalog.
            if ($intent === 'get_application_fee') {
                return $this->policy->blockReason($application, 'get_application_fee') === null
                    || in_array(
                        $application->status instanceof ApplicationStatus
                            ? $application->status
                            : ApplicationStatus::tryFrom((string) $application->status),
                        [ApplicationStatus::Draft, ApplicationStatus::DocumentsRejected, ApplicationStatus::PaymentPending],
                        true
                    );
            }

            return $this->isEligibleForIntent($application, $intent);
        })->values();
    }

    private function isEligibleForIntent(LicenseApplication $application, string $intent): bool
    {
        $action = AgentWorkflowIntentCatalog::actionName($intent);
        if ($action === null) {
            return true;
        }

        if (in_array($intent, ['get_application_status', 'get_application_next_step'], true)) {
            $status = $application->status instanceof ApplicationStatus
                ? $application->status
                : ApplicationStatus::tryFrom((string) $application->status);

            return $status !== null && in_array($status->value, ApplicationStatus::activeValues(), true);
        }

        if ($intent === 'get_required_documents') {
            return in_array(
                $application->status instanceof ApplicationStatus
                    ? $application->status
                    : ApplicationStatus::tryFrom((string) $application->status),
                [ApplicationStatus::Draft, ApplicationStatus::DocumentsRejected, ApplicationStatus::DocumentsUnderReview],
                true
            );
        }

        return $this->policy->blockReason($application, $action) === null
            || AgentApplicationStatusMap::isActionAllowed(
                $application->status instanceof ApplicationStatus
                    ? $application->status
                    : (ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft),
                $action
            );
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<int, LicenseApplication>
     */
    private function loadCandidatesByIds(User $citizen, array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        $apps = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('id', $ids)
            ->with(['licenseType', 'serviceType'])
            ->get()
            ->keyBy('id');

        // Preserve display order.
        return collect($ids)
            ->map(fn (int $id) => $apps->get($id))
            ->filter()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationCard(LicenseApplication $application): array
    {
        $serviceCode = (string) ($application->serviceType?->code ?? '');
        $licenseCode = (string) ($application->licenseType?->code ?? '');

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'service_type' => $serviceCode,
            'service_type_label' => (string) ($application->serviceType?->name ?? $serviceCode),
            'license_type' => $licenseCode,
            'license_type_label' => LicenseTypeSlotExtractor::labelAr($licenseCode),
            'status' => $application->status instanceof ApplicationStatus
                ? $application->status->value
                : (string) $application->status,
            'status_label' => ApplicationStatusLabelMapper::labelAr($application->status),
        ];
    }

    private function messageTypeForIntent(string $intent): string
    {
        return match ($intent) {
            'get_application_status' => 'application_status',
            'get_application_next_step' => 'application_next_step',
            'get_required_documents' => 'required_documents',
            'get_application_fee' => 'application_fee',
            'get_test_results' => 'test_results',
            'get_appointment_slots' => 'appointment_slots',
            'get_current_appointments' => 'current_appointments',
            'get_available_tests' => 'available_tests',
            default => 'workflow_continued',
        };
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function cancel(AIAgentSession $session, array $workflow): array
    {
        $this->clear($session);
        $reply = 'تم إلغاء عملية اختيار الطلب. يمكنك طلب أي خدمة أخرى.';
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'application_selection_cancelled',
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'application_selection_cancelled',
            'reply' => $reply,
            'intent' => (string) ($workflow['intent'] ?? 'unknown'),
            'confidence' => 1.0,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'pending_action' => null,
            'ui_payload' => ['cancelled' => true],
        ];
    }

    public function clear(AIAgentSession $session): void
    {
        $context = $session->context ?? [];
        unset($context['pending_workflow']);
        $session->context = $context;
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function writeWorkflow(AIAgentSession $session, array $workflow): void
    {
        $context = $session->context ?? [];
        $context['pending_workflow'] = $workflow;
        $session->context = $context;
        $session->last_message_at = now();
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function isExpired(array $workflow): bool
    {
        $expiresAt = (string) ($workflow['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }

        return now()->greaterThan($expiresAt);
    }

    private function documentFlowOwnsApplicationSelection(AIAgentSession $session): bool
    {
        $flow = $session->context['document_flow'] ?? null;
        if (! is_array($flow)) {
            return false;
        }

        $state = DocumentFlowState::tryFrom((string) ($flow['state'] ?? ''));

        return $state === DocumentFlowState::ApplicationSelection;
    }

    private function storeAssistantMessage(AIAgentSession $session, string $content, array $metadata = []): void
    {
        AIAgentMessage::query()->create([
            'session_id' => $session->id,
            'role' => AgentMessageRole::Assistant,
            'content' => $content,
            'metadata' => $metadata ?: null,
        ]);
    }
}
