<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentIntent;
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
use App\Modules\AIAgent\Support\AgentTranslator;
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
        private readonly AgentAppointmentOptionService $appointmentOptions,
        private readonly AgentLicenseOptionService $licenseOptions,
        private readonly AgentOtherLicenseServicesHandler $otherLicenseServices,
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
            PendingWorkflowState::AwaitingLicenseChoice->value,
            PendingWorkflowState::AwaitingAppointmentChoice->value,
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
     * Start/continue appointment or slot selection when payload asks for those slots.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichAppointmentContinuationIfNeeded(
        User $citizen,
        AIAgentSession $session,
        array $payload,
        string $originalMessage = '',
    ): array {
        $missing = $payload['missing_slots'] ?? [];
        if (! is_array($missing)) {
            return $payload;
        }

        $intent = (string) ($payload['intent'] ?? '');
        if ($intent === '') {
            return $payload;
        }

        $collected = is_array($payload['collected_slots'] ?? null) ? $payload['collected_slots'] : [];
        $applicationId = (int) ($collected['application_id'] ?? 0);

        if ($applicationId < 1) {
            $applicationId = (int) (($session->context['last_application_id'] ?? 0));
        }

        if ($applicationId < 1) {
            $candidates = $this->resolveCandidates($citizen, $intent);
            if ($candidates->count() === 1) {
                $applicationId = (int) $candidates->first()->id;
            }
        }

        $ttl = (int) config('ai.agent.pending_workflow_ttl_seconds', 900);
        $workflow = $this->getWorkflow($session);
        if ($workflow === null || $this->isExpired($workflow)) {
            $workflow = [
                'workflow_id' => (string) Str::uuid(),
                'state' => PendingWorkflowState::AwaitingAppointmentSlotChoice->value,
                'intent' => $intent,
                'original_message' => mb_substr($originalMessage, 0, 500),
                'required_slots' => array_values(array_unique(array_merge(
                    $applicationId > 0 ? [] : ['application_choice'],
                    in_array('appointment_choice', $missing, true) ? ['appointment_choice'] : [],
                    in_array('appointment_slot_choice', $missing, true) ? ['appointment_slot_choice'] : [],
                ))),
                'current_required_slot' => in_array('appointment_choice', $missing, true)
                    ? 'appointment_choice'
                    : 'appointment_slot_choice',
                'required_slot' => in_array('appointment_choice', $missing, true)
                    ? 'appointment_choice'
                    : 'appointment_slot_choice',
                'candidate_application_ids' => $applicationId > 0 ? [$applicationId] : [],
                'collected_slots' => $applicationId > 0 ? ['application_id' => $applicationId] : [],
                'metadata' => [
                    'action_name' => AgentWorkflowIntentCatalog::actionName($intent),
                ],
                'created_at' => now()->toIso8601String(),
                'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            ];
            $this->writeWorkflow($session, $workflow);
            $session->current_intent = $intent;
            $session->save();
        } else {
            $workflow['intent'] = $intent;
            $existingCollected = is_array($workflow['collected_slots'] ?? null) ? $workflow['collected_slots'] : [];
            if ($applicationId > 0) {
                $existingCollected['application_id'] = $applicationId;
            }
            $workflow['collected_slots'] = $existingCollected;
            $this->writeWorkflow($session, $workflow);
        }

        if (in_array('appointment_choice', $missing, true)) {
            $appointments = $this->appointmentOptions->bookedAppointmentsForCitizen(
                $citizen,
                $applicationId > 0 ? $applicationId : null
            );

            if ($appointments->isEmpty()) {
                $this->clear($session);

                return [
                    'session_id' => $session->id,
                    'message_type' => 'no_eligible_appointment',
                    'reply' => AgentTranslator::message('ai_agent.appointments.choose.none'),
                    'intent' => $intent,
                    'confidence' => 1.0,
                    'missing_slots' => [],
                    'requires_confirmation' => false,
                    'pending_action' => null,
                    'ui_payload' => ['appointments' => []],
                ];
            }

            if ($appointments->count() === 1) {
                return $this->resumeWithAppointment($citizen, $session, (int) $appointments->first()->id);
            }

            $result = $this->appointmentSelectionRequiredResponse($citizen, $session, $workflow, $appointments);
            unset($result['keep_pending_workflow']);

            return $result;
        }

        if (in_array('appointment_slot_choice', $missing, true)) {
            if ($applicationId < 1) {
                return $payload;
            }

            $appointmentId = isset($collected['appointment_id']) ? (int) $collected['appointment_id'] : null;
            $result = $this->appointmentSlotSelectionRequiredResponse(
                $citizen,
                $session,
                $workflow,
                $applicationId,
                $appointmentId
            );
            unset($result['keep_pending_workflow']);

            return $result;
        }

        return $payload;
    }

    /**
     * Multi-license selection for renew / lost / damaged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichLicenseSelectionIfNeeded(
        User $citizen,
        AIAgentSession $session,
        array $payload,
        string $originalMessage = '',
    ): array {
        $missing = $payload['missing_slots'] ?? [];
        if (! is_array($missing) || ! in_array('related_license_id', $missing, true)) {
            return $payload;
        }

        $intent = (string) ($payload['intent'] ?? '');
        $service = match ($intent) {
            AgentIntent::CreateRenewLicenseApplication->value => \App\Enums\ServiceCode::RenewLicense,
            AgentIntent::CreateLostReplacementApplication->value => \App\Enums\ServiceCode::LostReplacement,
            AgentIntent::CreateDamagedReplacementApplication->value => \App\Enums\ServiceCode::DamagedReplacement,
            default => null,
        };
        if ($service === null) {
            return $payload;
        }

        $licenses = $this->licenseOptions->eligibleLicenses($citizen, $service);
        if ($licenses->isEmpty()) {
            return $payload;
        }
        if ($licenses->count() === 1) {
            return $this->otherLicenseServices->confirmationPayload(
                $citizen,
                $service,
                AgentIntent::from($intent),
                AgentTranslator::getLocale(),
                $licenses->first()
            );
        }

        $ttl = (int) config('ai.agent.pending_workflow_ttl_seconds', 900);
        $workflow = [
            'workflow_id' => (string) Str::uuid(),
            'state' => PendingWorkflowState::AwaitingLicenseChoice->value,
            'intent' => $intent,
            'original_message' => mb_substr($originalMessage, 0, 500),
            'required_slots' => ['related_license_id'],
            'current_required_slot' => 'related_license_id',
            'required_slot' => 'related_license_id',
            'candidate_license_ids' => $licenses->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'collected_slots' => [
                'service_type_code' => $service->value,
            ],
            'metadata' => ['action_name' => 'create_application'],
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];
        $this->writeWorkflow($session, $workflow);
        $session->current_intent = $intent;
        $session->save();

        return $this->licenseSelectionRequiredResponse($citizen, $session, $workflow, $licenses);
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

        // Slot-choice / appointment-choice / license-choice stages: resolve deterministically.
        if ($state === PendingWorkflowState::AwaitingAppointmentSlotChoice->value) {
            return $this->handleAwaitingSlotChoice($citizen, $session, $workflow, $message);
        }

        if ($state === PendingWorkflowState::AwaitingAppointmentChoice->value) {
            return $this->handleAwaitingAppointmentChoice($citizen, $session, $workflow, $message);
        }

        if ($state === PendingWorkflowState::AwaitingLicenseChoice->value) {
            return $this->handleAwaitingLicenseChoice($citizen, $session, $workflow, $message);
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
        $state = (string) ($workflow['state'] ?? '');

        if ($state === PendingWorkflowState::AwaitingAppointmentSlotChoice->value) {
            $applicationId = (int) ($workflow['collected_slots']['application_id'] ?? 0);
            $appointmentId = isset($workflow['collected_slots']['appointment_id'])
                ? (int) $workflow['collected_slots']['appointment_id']
                : null;
            $result = $this->appointmentSlotSelectionRequiredResponse(
                $citizen,
                $session,
                $workflow,
                $applicationId,
                $appointmentId
            );
            unset($result['keep_pending_workflow']);

            return $result;
        }

        if ($state === PendingWorkflowState::AwaitingAppointmentChoice->value) {
            $applicationId = isset($workflow['collected_slots']['application_id'])
                ? (int) $workflow['collected_slots']['application_id']
                : null;
            $appointments = $this->appointmentOptions->bookedAppointmentsForCitizen($citizen, $applicationId);
            $candidateIds = array_map('intval', $workflow['candidate_appointment_ids'] ?? []);
            if ($candidateIds !== []) {
                $appointments = $appointments->whereIn('id', $candidateIds)->values();
            }
            $result = $this->appointmentSelectionRequiredResponse($citizen, $session, $workflow, $appointments);
            unset($result['keep_pending_workflow']);

            return $result;
        }

        if ($state === PendingWorkflowState::AwaitingLicenseChoice->value) {
            $serviceCode = (string) ($workflow['collected_slots']['service_type_code'] ?? '');
            $service = \App\Enums\ServiceCode::tryFrom($serviceCode);
            $licenses = $service
                ? $this->licenseOptions->eligibleLicenses($citizen, $service)
                : collect();
            $candidateIds = array_map('intval', $workflow['candidate_license_ids'] ?? []);
            if ($candidateIds !== []) {
                $licenses = $licenses->whereIn('id', $candidateIds)->values();
            }

            return $this->licenseSelectionRequiredResponse($citizen, $session, $workflow, $licenses);
        }

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

        // book_appointment: collect slot before confirmation.
        if ($intent === 'book_appointment' || $actionName === 'book_appointment') {
            return $this->appointmentSlotSelectionRequiredResponse(
                $citizen,
                $session,
                $this->getWorkflow($session) ?? [],
                (int) $application->id
            );
        }

        // reschedule/cancel: select appointment (or proceed if only one).
        if (in_array($intent, ['reschedule_appointment', 'cancel_appointment'], true)) {
            $appointments = $this->appointmentOptions->bookedAppointmentsForCitizen($citizen, (int) $application->id);
            if ($appointments->isEmpty()) {
                $this->clear($session);

                return [
                    'session_id' => $session->id,
                    'message_type' => 'no_eligible_appointment',
                    'reply' => AgentTranslator::message('ai_agent.appointments.choose.none'),
                    'intent' => $intent,
                    'confidence' => 1.0,
                    'missing_slots' => [],
                    'requires_confirmation' => false,
                    'pending_action' => null,
                    'ui_payload' => ['appointments' => []],
                ];
            }

            $workflow = $this->getWorkflow($session) ?? [];
            $workflow['intent'] = $intent;
            $collected = is_array($workflow['collected_slots'] ?? null) ? $workflow['collected_slots'] : [];
            $collected['application_id'] = (int) $application->id;
            $workflow['collected_slots'] = $collected;

            if ($appointments->count() === 1) {
                $this->writeWorkflow($session, $workflow);

                return $this->resumeWithAppointment($citizen, $session, (int) $appointments->first()->id);
            }

            return $this->appointmentSelectionRequiredResponse($citizen, $session, $workflow, $appointments);
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
     * Safe multi-slot continuation: keep pending workflow and request appointment slot with real options.
     *
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function appointmentSlotSelectionRequiredResponse(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        int $applicationId,
        ?int $appointmentId = null,
    ): array {
        if ($applicationId <= 0 && $appointmentId === null) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.appointments.slots.error_application'),
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $intent = (string) ($workflow['intent'] ?? 'book_appointment');
        $application = null;
        $slots = collect();

        if ($appointmentId !== null && $appointmentId > 0) {
            $appointment = \App\Models\TestAppointment::query()
                ->where('citizen_id', $citizen->id)
                ->whereKey($appointmentId)
                ->with(['application', 'testType', 'appointmentSlot'])
                ->first();

            if ($appointment === null) {
                $this->clear($session);
                throw new ApiException(
                    AgentTranslator::message('ai_agent.appointments.choose.not_found'),
                    404,
                    [],
                    [],
                    'APPOINTMENT_NOT_OWNED'
                );
            }

            $applicationId = (int) $appointment->application_id;
            $application = LicenseApplication::query()
                ->where('citizen_id', $citizen->id)
                ->whereKey($applicationId)
                ->with(['licenseType', 'serviceType'])
                ->first();
            $slots = $this->appointmentOptions->availableSlotsForAppointment($citizen, $appointment);
        } else {
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

            $slots = $this->appointmentOptions->availableSlotsForApplication($citizen, $application);
        }

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
        $workflow['required_slots'] = array_values(array_unique(array_merge(
            array_values($workflow['required_slots'] ?? ['application_choice']),
            ['appointment_slot_choice']
        )));
        $workflow['current_required_slot'] = 'appointment_slot_choice';
        $workflow['required_slot'] = 'appointment_slot_choice';
        $collected = is_array($workflow['collected_slots'] ?? null) ? $workflow['collected_slots'] : [];
        $collected['application_id'] = $applicationId;
        if ($appointmentId !== null && $appointmentId > 0) {
            $collected['appointment_id'] = $appointmentId;
        }
        $workflow['collected_slots'] = $collected;
        $workflow['candidate_slot_ids'] = $slots->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->writeWorkflow($session, $workflow);

        $session->current_intent = $intent;
        $session->save();

        $buttons = $this->appointmentOptions->buildSlotButtons(
            $citizen,
            $session,
            $slots,
            $applicationId,
            (string) ($workflow['workflow_id'] ?? ''),
            $intent
        );

        $reply = $this->appointmentOptions->slotPrompt($buttons === []);
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'appointment_slot_selection_required',
            'intent' => $intent,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'appointment_slot_selection_required',
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => 1.0,
            'missing_slots' => ['appointment_slot_choice'],
            'requires_confirmation' => false,
            'pending_action' => null,
            'application' => $this->applicationCard($application),
            'ui_payload' => [
                'selection_type' => 'appointment_slot',
                'application' => $this->applicationCard($application),
                'slots' => $buttons,
            ],
            'keep_pending_workflow' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function handleAwaitingSlotChoice(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        string $message,
    ): array {
        $applicationId = (int) ($workflow['collected_slots']['application_id'] ?? 0);
        $appointmentId = isset($workflow['collected_slots']['appointment_id'])
            ? (int) $workflow['collected_slots']['appointment_id']
            : null;

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

        $slots = $appointmentId
            ? $this->appointmentOptions->availableSlotsForAppointment(
                $citizen,
                \App\Models\TestAppointment::query()->whereKey($appointmentId)->where('citizen_id', $citizen->id)->firstOrFail()
            )
            : $this->appointmentOptions->availableSlotsForApplication($citizen, $application);

        $candidateIds = array_map('intval', $workflow['candidate_slot_ids'] ?? $slots->pluck('id')->all());
        $slots = $slots->whereIn('id', $candidateIds)->values();
        if ($slots->isEmpty() && $candidateIds !== []) {
            $slots = \App\Models\AppointmentSlot::query()->whereIn('id', $candidateIds)->get()->values();
        }

        $resolution = $this->appointmentOptions->resolveSlotFromText($message, $slots);
        if (($resolution['status'] ?? '') === 'matched' && isset($resolution['slot_id'])) {
            return $this->completeSlotSelection($citizen, $session, $workflow, (int) $resolution['slot_id']);
        }

        return $this->appointmentSlotSelectionRequiredResponse(
            $citizen,
            $session,
            $workflow,
            $applicationId,
            $appointmentId
        );
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function handleAwaitingAppointmentChoice(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        string $message,
    ): array {
        $intent = (string) ($workflow['intent'] ?? '');
        $applicationId = isset($workflow['collected_slots']['application_id'])
            ? (int) $workflow['collected_slots']['application_id']
            : null;
        $appointments = $this->appointmentOptions->bookedAppointmentsForCitizen($citizen, $applicationId);
        $candidateIds = array_map('intval', $workflow['candidate_appointment_ids'] ?? []);
        if ($candidateIds !== []) {
            $appointments = $appointments->whereIn('id', $candidateIds)->values();
        }

        $resolution = $this->appointmentOptions->resolveAppointmentFromText($message, $appointments);
        if (($resolution['status'] ?? '') === 'matched' && isset($resolution['appointment_id'])) {
            return $this->resumeWithAppointment($citizen, $session, (int) $resolution['appointment_id']);
        }

        return $this->appointmentSelectionRequiredResponse($citizen, $session, $workflow, $appointments);
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  \Illuminate\Support\Collection<int, \App\Models\TestAppointment>  $appointments
     * @return array<string, mixed>
     */
    private function appointmentSelectionRequiredResponse(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        $appointments,
    ): array {
        $intent = (string) ($workflow['intent'] ?? 'cancel_appointment');
        $workflow['state'] = PendingWorkflowState::AwaitingAppointmentChoice->value;
        $workflow['required_slots'] = array_values(array_unique(array_merge(
            array_values($workflow['required_slots'] ?? []),
            ['appointment_choice']
        )));
        $workflow['current_required_slot'] = 'appointment_choice';
        $workflow['required_slot'] = 'appointment_choice';
        $workflow['candidate_appointment_ids'] = $appointments->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->writeWorkflow($session, $workflow);

        $buttons = $this->appointmentOptions->buildAppointmentButtons(
            $citizen,
            $session,
            $appointments,
            (string) ($workflow['workflow_id'] ?? ''),
            $intent
        );

        $reply = $this->appointmentOptions->appointmentPrompt($buttons === []);
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'appointment_selection_required',
            'intent' => $intent,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'appointment_selection_required',
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => 1.0,
            'missing_slots' => ['appointment_choice'],
            'requires_confirmation' => false,
            'pending_action' => null,
            'ui_payload' => [
                'selection_type' => 'appointment',
                'appointments' => $buttons,
            ],
            'keep_pending_workflow' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function selectAppointmentSlotByToken(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        $workflow = $this->assertActiveForInteraction($session);
        if (($workflow['state'] ?? null) !== PendingWorkflowState::AwaitingAppointmentSlotChoice->value) {
            throw new ApiException(
                'لا توجد عملية اختيار موعد قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $verified = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_APPOINTMENT_SLOT,
            (string) ($workflow['workflow_id'] ?? ''),
            (string) ($workflow['intent'] ?? '')
        );

        $slotId = (int) ($verified['slot_id'] ?? 0);
        if ($slotId < 1) {
            throw new ApiException(
                'رمز اختيار الموعد غير صالح.',
                422,
                [],
                [],
                'APPLICATION_SELECTION_TOKEN_INVALID'
            );
        }

        return $this->completeSlotSelection($citizen, $session, $workflow, $slotId);
    }

    /**
     * @return array<string, mixed>
     */
    public function selectAppointmentByToken(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        $workflow = $this->assertActiveForInteraction($session);
        if (($workflow['state'] ?? null) !== PendingWorkflowState::AwaitingAppointmentChoice->value) {
            throw new ApiException(
                'لا توجد عملية اختيار موعد قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $verified = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_APPOINTMENT,
            (string) ($workflow['workflow_id'] ?? ''),
            (string) ($workflow['intent'] ?? '')
        );

        $appointmentId = (int) ($verified['appointment_id'] ?? 0);
        if ($appointmentId < 1) {
            throw new ApiException(
                'رمز اختيار الموعد غير صالح.',
                422,
                [],
                [],
                'APPLICATION_SELECTION_TOKEN_INVALID'
            );
        }

        return $this->resumeWithAppointment($citizen, $session, $appointmentId);
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function completeSlotSelection(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        int $slotId,
    ): array {
        $intent = (string) ($workflow['intent'] ?? 'book_appointment');
        $applicationId = (int) ($workflow['collected_slots']['application_id'] ?? 0);
        $appointmentId = isset($workflow['collected_slots']['appointment_id'])
            ? (int) $workflow['collected_slots']['appointment_id']
            : null;

        $candidateSlotIds = array_map('intval', $workflow['candidate_slot_ids'] ?? []);
        if ($candidateSlotIds !== [] && ! in_array($slotId, $candidateSlotIds, true)) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.appointments.slots.invalid_choice'),
                422,
                [],
                [],
                'APPOINTMENT_SLOT_SELECTION_INVALID'
            );
        }

        $slot = \App\Models\AppointmentSlot::query()->whereKey($slotId)->first();
        if ($slot === null || ! $slot->is_active || $slot->booked_count >= $slot->capacity) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.appointments.slots.stale'),
                422,
                [],
                [],
                'APPOINTMENT_SLOT_NO_LONGER_AVAILABLE'
            );
        }

        if ($intent === 'reschedule_appointment') {
            $arguments = [
                'appointment_id' => $appointmentId,
                'appointment_slot_id' => $slotId,
                'application_id' => $applicationId,
            ];
            $actionName = 'reschedule_appointment';
        } else {
            $arguments = [
                'application_id' => $applicationId,
                'appointment_slot_id' => $slotId,
            ];
            $actionName = 'book_appointment';
        }

        AgentActionArgumentValidator::assertComplete($actionName, $arguments);

        $reply = AgentTranslator::message('ai_agent.appointments.confirm.prompt');
        $action = AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => $actionName,
            'arguments' => $arguments,
            'status' => AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => $reply,
        ]);

        $this->clear($session);
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'appointment_confirmation_required',
            'action_id' => $action->id,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'appointment_confirmation_required',
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
            'ui_payload' => [
                'requires_confirmation' => true,
                'action_name' => $actionName,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeWithAppointment(User $citizen, AIAgentSession $session, int $appointmentId): array
    {
        $workflow = $this->getWorkflow($session);
        if ($workflow === null) {
            throw new ApiException(
                'لا توجد عملية اختيار موعد قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        $intent = (string) ($workflow['intent'] ?? '');
        $appointment = \App\Models\TestAppointment::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($appointmentId)
            ->with(['application', 'testType', 'appointmentSlot'])
            ->first();

        if ($appointment === null) {
            $this->clear($session);
            throw new ApiException(
                AgentTranslator::message('ai_agent.appointments.choose.not_found'),
                404,
                [],
                [],
                'APPOINTMENT_NOT_OWNED'
            );
        }

        $candidateIds = array_map('intval', $workflow['candidate_appointment_ids'] ?? []);
        if ($candidateIds !== [] && ! in_array($appointmentId, $candidateIds, true)) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.appointments.choose.invalid'),
                422,
                [],
                [],
                'APPOINTMENT_SELECTION_INVALID'
            );
        }

        $collected = is_array($workflow['collected_slots'] ?? null) ? $workflow['collected_slots'] : [];
        $collected['appointment_id'] = $appointmentId;
        $collected['application_id'] = (int) $appointment->application_id;
        $workflow['collected_slots'] = $collected;

        if ($intent === 'cancel_appointment') {
            $arguments = [
                'appointment_id' => $appointmentId,
                'application_id' => (int) $appointment->application_id,
            ];
            AgentActionArgumentValidator::assertComplete('cancel_appointment', $arguments);

            $reply = AgentTranslator::message('ai_agent.appointments.cancel.confirm_prompt');
            $action = AIAgentAction::query()->create([
                'session_id' => $session->id,
                'user_id' => $citizen->id,
                'action_name' => 'cancel_appointment',
                'arguments' => $arguments,
                'status' => AgentActionStatus::AwaitingConfirmation,
                'requires_confirmation' => true,
                'confirmation_message' => $reply,
            ]);

            $this->clear($session);
            $this->storeAssistantMessage($session, $reply, [
                'message_type' => 'appointment_confirmation_required',
                'action_id' => $action->id,
            ]);

            return [
                'session_id' => $session->id,
                'message_type' => 'appointment_confirmation_required',
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
                'ui_payload' => [
                    'requires_confirmation' => true,
                    'action_name' => 'cancel_appointment',
                ],
            ];
        }

        // reschedule → ask for replacement slot
        $this->writeWorkflow($session, $workflow);

        return $this->appointmentSlotSelectionRequiredResponse(
            $citizen,
            $session,
            $workflow,
            (int) $appointment->application_id,
            $appointmentId
        );
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  Collection<int, \App\Models\License>  $licenses
     * @return array<string, mixed>
     */
    private function licenseSelectionRequiredResponse(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        $licenses,
    ): array {
        $intent = (string) ($workflow['intent'] ?? '');
        $workflow['state'] = PendingWorkflowState::AwaitingLicenseChoice->value;
        $workflow['candidate_license_ids'] = $licenses->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->writeWorkflow($session, $workflow);

        $buttons = $this->licenseOptions->buildLicenseButtons(
            $citizen,
            $session,
            $licenses,
            (string) ($workflow['workflow_id'] ?? ''),
            $intent
        );

        $reply = AgentTranslator::message('ai_agent.other_license.choose');
        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'license_selection_required',
            'intent' => $intent,
        ]);

        return [
            'session_id' => $session->id,
            'message_type' => 'license_selection_required',
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => 1.0,
            'missing_slots' => ['related_license_id'],
            'requires_confirmation' => false,
            'pending_action' => null,
            'ui_payload' => [
                'selection_type' => 'license',
                'licenses' => $buttons,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function handleAwaitingLicenseChoice(
        User $citizen,
        AIAgentSession $session,
        array $workflow,
        string $message,
    ): array {
        $serviceCode = (string) ($workflow['collected_slots']['service_type_code'] ?? '');
        $service = \App\Enums\ServiceCode::tryFrom($serviceCode);
        $licenses = $service
            ? $this->licenseOptions->eligibleLicenses($citizen, $service)
            : collect();
        $candidateIds = array_map('intval', $workflow['candidate_license_ids'] ?? []);
        if ($candidateIds !== []) {
            $licenses = $licenses->whereIn('id', $candidateIds)->values();
        }

        $resolution = $this->licenseOptions->resolveFromText($message, $licenses);
        if (($resolution['status'] ?? '') === 'matched' && isset($resolution['license_id'])) {
            return $this->resumeWithLicense($citizen, $session, (int) $resolution['license_id']);
        }

        return $this->licenseSelectionRequiredResponse($citizen, $session, $workflow, $licenses);
    }

    /**
     * @return array<string, mixed>
     */
    public function selectLicenseByToken(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        $workflow = $this->assertActiveForInteraction($session);
        if (($workflow['state'] ?? null) !== PendingWorkflowState::AwaitingLicenseChoice->value) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.other_license.choose'),
                422,
                [],
                [],
                'PENDING_WORKFLOW_STATE_INVALID'
            );
        }

        $verified = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_LICENSE,
            (string) ($workflow['workflow_id'] ?? ''),
            (string) ($workflow['intent'] ?? '')
        );

        $licenseId = (int) ($verified['license_id'] ?? 0);
        if ($licenseId < 1) {
            throw new ApiException(
                'رمز اختيار الرخصة غير صالح.',
                422,
                [],
                [],
                'APPLICATION_SELECTION_TOKEN_INVALID'
            );
        }

        return $this->resumeWithLicense($citizen, $session, $licenseId);
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeWithLicense(User $citizen, AIAgentSession $session, int $licenseId): array
    {
        $workflow = $this->getWorkflow($session);
        if ($workflow === null) {
            throw new ApiException(
                'لا توجد عملية اختيار رخصة قيد الانتظار.',
                422,
                [],
                [],
                'PENDING_WORKFLOW_NOT_FOUND'
            );
        }

        $candidateIds = array_map('intval', $workflow['candidate_license_ids'] ?? []);
        if ($candidateIds !== [] && ! in_array($licenseId, $candidateIds, true)) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.other_license.invalid_choice'),
                422,
                [],
                [],
                'LICENSE_SELECTION_INVALID'
            );
        }

        $intent = (string) ($workflow['intent'] ?? '');
        $service = match ($intent) {
            AgentIntent::CreateRenewLicenseApplication->value => \App\Enums\ServiceCode::RenewLicense,
            AgentIntent::CreateLostReplacementApplication->value => \App\Enums\ServiceCode::LostReplacement,
            AgentIntent::CreateDamagedReplacementApplication->value => \App\Enums\ServiceCode::DamagedReplacement,
            default => \App\Enums\ServiceCode::tryFrom((string) ($workflow['collected_slots']['service_type_code'] ?? '')),
        };

        if ($service === null) {
            $this->clear($session);
            throw new ApiException(
                AgentTranslator::message('ai_agent.other_license.none_eligible'),
                422,
                [],
                [],
                'LICENSE_NO_LONGER_ELIGIBLE'
            );
        }

        $license = \App\Models\License::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($licenseId)
            ->with('licenseType')
            ->first();

        if ($license === null || ! $this->licenseOptions->eligibleLicenses($citizen, $service)->contains(
            fn ($item): bool => (int) $item->id === $licenseId
        )) {
            $this->clear($session);
            throw new ApiException(
                AgentTranslator::message('ai_agent.other_license.none_eligible'),
                422,
                [],
                [],
                'LICENSE_NO_LONGER_ELIGIBLE'
            );
        }

        $payload = $this->otherLicenseServices->confirmationPayload(
            $citizen,
            $service,
            AgentIntent::from($intent),
            AgentTranslator::getLocale(),
            $license
        );

        if (! empty($payload['proposed_action']['name']) && ($payload['requires_confirmation'] ?? false)) {
            $proposed = $payload['proposed_action'];
            AgentActionArgumentValidator::assertComplete(
                (string) $proposed['name'],
                is_array($proposed['arguments'] ?? null) ? $proposed['arguments'] : []
            );

            $action = AIAgentAction::query()->create([
                'session_id' => $session->id,
                'user_id' => $citizen->id,
                'action_name' => (string) $proposed['name'],
                'arguments' => $proposed['arguments'],
                'status' => AgentActionStatus::AwaitingConfirmation,
                'requires_confirmation' => true,
                'confirmation_message' => (string) ($payload['reply'] ?? ''),
            ]);

            $this->clear($session);
            $reply = (string) ($payload['reply'] ?? '');
            $this->storeAssistantMessage($session, $reply, [
                'message_type' => 'license_service_confirmation_required',
                'action_id' => $action->id,
            ]);

            return [
                'session_id' => $session->id,
                'message_type' => 'license_service_confirmation_required',
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
                'ui_payload' => $payload['ui_payload'] ?? [
                    'requires_confirmation' => true,
                    'action_name' => $action->action_name,
                ],
            ];
        }

        $this->clear($session);

        return array_merge($payload, ['session_id' => $session->id]);
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
