<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentMessageIntentMatcher;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\AgentUserConfirmationDetector;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AIAgentService
{
    public function __construct(
        private readonly AgentPreProcessor $preProcessor,
        private readonly AgentIntentDetector $intentDetector,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly GeminiAgentClient $geminiClient,
        private readonly AgentPostProcessor $postProcessor,
        private readonly AgentSlotFiller $slotFiller,
        private readonly AgentEvaluationService $evaluationService,
        private readonly AgentSessionContextService $sessionContext,
        private readonly AIAgentActionService $actionService,
        private readonly AgentDocumentFlowService $documentFlow,
        private readonly AgentPendingWorkflowService $pendingWorkflow,
        private readonly AgentLocaleContext $localeContext,
        private readonly AgentSessionLocaleManager $sessionLocaleManager,
        private readonly AgentResponseLocale $responseLocale,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handleMessage(User $user, string $message, ?int $sessionId = null): array
    {
        if (! config('ai.enabled')) {
            throw new ApiException('AI agent is currently disabled.', 503);
        }

        // Load or create session first to get session locale
        $session = $this->resolveSession($user, $sessionId);
        $sessionLocale = $this->sessionLocaleManager->getSessionLocale($session);

        // Preprocess with session locale context
        $prepared = $this->preProcessor->process($message, $sessionLocale);
        
        if ($prepared['flags']['empty']) {
            throw new ApiException('Message cannot be empty.', 422, ['message' => ['Message is required.']]);
        }

        $detection = $prepared['language_detection'];

        // Determine final locale for this request
        $requestLocale = $this->sessionLocaleManager->resolveLocaleForRequest($session, $detection);

        // Set locale in request-scoped context
        $this->localeContext->setLocale($requestLocale);
        $this->localeContext->setDetectionMetadata(
            $detection['locale'],
            $detection['confidence'],
            $detection['source']
        );
        app()->setLocale($requestLocale);

        // Update session locale if confident or explicit
        $this->sessionLocaleManager->updateIfConfident($session, $detection);

        return DB::transaction(function () use ($user, $prepared, $session, $requestLocale) {
            $userMessage = $prepared['message'];

            $state = $this->sessionContext->mergeUserMessage($session, $userMessage);

            $this->storeMessage($session, AgentMessageRole::User, $userMessage);

            $awaitingAction = $this->findAwaitingConfirmationAction($session);

            if ($awaitingAction !== null) {
                if (AgentUserConfirmationDetector::isAffirmative($userMessage)) {
                    return $this->formatMessageResponseFromActionResult(
                        $session,
                        $this->actionService->confirm($user, $awaitingAction->id)
                    );
                }

                if (AgentUserConfirmationDetector::isNegative($userMessage)) {
                    return $this->formatMessageResponseFromActionResult(
                        $session,
                        $this->actionService->cancel($user, $awaitingAction->id),
                        cancelled: true
                    );
                }

                if (AgentWorkflowPhraseMatcher::isWorkflowQuery(
                    $userMessage,
                    $session->current_intent,
                    $this->sessionContext->resolveLastDiscussedApplicationId($session)
                )) {
                    $awaitingAction->status = AgentActionStatus::Cancelled;
                    $awaitingAction->save();
                    $awaitingAction = null;
                }
            }

            // Conversational document-upload decisions are deterministic and must not go to Gemini.
            if ($this->documentFlow->shouldHandleTextDecision($session, $userMessage)) {
                return $this->responseLocale->decorate(
                    $this->documentFlow->handleTextDecision($user, $session, $userMessage)
                );
            }

            // Pending application selection / expiry must be resolved before Gemini / general_help.
            if ($this->pendingWorkflow->shouldHandlePendingMessage($session)) {
                $pendingResult = $this->pendingWorkflow->handleAwaitingMessage($user, $session, $userMessage);
                if ($pendingResult !== null) {
                    return $this->responseLocale->decorate($pendingResult);
                }
                // null => clear topic change; fall through to normal intent detection.
                $session->refresh();
            }

            // Submit-for-review phrases also contain "الوثائق" — do not steal them into the upload offer flow.
            if (AgentWorkflowPhraseMatcher::isRequiredDocumentsQuery($userMessage)
                && ! AgentWorkflowPhraseMatcher::isSubmitDocumentsForReviewQuery($userMessage)) {
                return $this->responseLocale->decorate(
                    $this->documentFlow->startRequiredDocumentsFlow($user, $session)
                );
            }

            $startedAt = hrtime(true);
            $wasFallback = false;
            $payload = null;

            try {
                $contents = $this->contextBuilder->buildGeminiContents($session);
                $raw = $this->geminiClient->generateStructuredResponse(
                    $this->contextBuilder->buildSystemInstruction($session, $user),
                    $contents
                );
                $payload = $this->postProcessor->normalize(
                    $raw,
                    $userMessage,
                    $requestLocale
                );
            } catch (\Throwable) {
                $payload = null;
            }

            if ($payload === null) {
                $wasFallback = true;
                $payload = $this->intentDetector->detectFallback(
                    $user,
                    $userMessage,
                    $session,
                    $requestLocale
                );
            }

            $payload = $this->intentDetector->applyDeterministicOverrides($user, $session, $userMessage, $payload, $state);
            $payload = $this->applyReadOnlyConfirmationDefaults($payload);

            $payload = $this->slotFiller->apply($user, $session, $payload, $userMessage, $state);
            $payload = $this->postProcessor->enforceProfileApprovalRules($user, $payload);
            $payload = $this->postProcessor->enforceDuplicateApplicationRules($user, $payload);
            $payload = $this->postProcessor->applyConfirmationReply($payload);
            $payload = AgentTranslator::localizePayload($payload);

            // Multi-application intents: create pending_workflow and return selection buttons.
            if (in_array('application_choice', $payload['missing_slots'] ?? [], true)) {
                return $this->responseLocale->decorate(
                    $this->pendingWorkflow->enrichPayloadIfNeeded($user, $session, $payload, $userMessage)
                );
            }

            // Renew / lost / damaged: multi-license selection.
            if (in_array('related_license_id', $payload['missing_slots'] ?? [], true)) {
                return $this->responseLocale->decorate(
                    $this->pendingWorkflow->enrichLicenseSelectionIfNeeded(
                        $user,
                        $session,
                        $payload,
                        $userMessage
                    )
                );
            }

            // Appointment / slot selection continuation (single-app book/reschedule/cancel).
            if (in_array('appointment_slot_choice', $payload['missing_slots'] ?? [], true)
                || in_array('appointment_choice', $payload['missing_slots'] ?? [], true)) {
                return $this->responseLocale->decorate(
                    $this->pendingWorkflow->enrichAppointmentContinuationIfNeeded(
                        $user,
                        $session,
                        $payload,
                        $userMessage
                    )
                );
            }

            $state = $this->sessionContext->finalizeState($state, $payload, $userMessage);

            $session->current_intent = $payload['intent'];
            $session->last_message_at = now();
            $session->save();

            $pendingAction = $this->persistProposedAction($user, $session, $payload);

            $executed = $this->maybeExecuteReadOnlyAction($user, $session, $pendingAction, $payload);
            if ($executed !== null) {
                return $executed;
            }

            $assistantMessage = $this->storeMessage(
                $session,
                AgentMessageRole::Assistant,
                (string) $payload['reply'],
                [
                    'intent' => $payload['intent'],
                    'confidence' => $payload['confidence'],
                    'missing_slots' => $payload['missing_slots'],
                    'pending_action_id' => $pendingAction?->id,
                ]
            );

            $this->slotFiller->persistSessionContext($session, $payload, $state, $pendingAction);
            $session->save();

            $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $this->evaluationService->record(
                $session,
                $assistantMessage,
                $payload,
                $latencyMs,
                $wasFallback,
                $pendingAction?->action_name
            );

            return $this->formatMessageResponse($session, $payload, $pendingAction);
        });
    }

    public function listSessions(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return AIAgentSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function getSessionForUser(User $user, int $sessionId): AIAgentSession
    {
        return AIAgentSession::query()
            ->where('user_id', $user->id)
            ->with([
                'messages' => fn ($q) => $q->orderBy('id'),
                'actions' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->findOrFail($sessionId);
    }

    private function resolveSession(User $user, ?int $sessionId): AIAgentSession
    {
        if ($sessionId !== null) {
            $session = AIAgentSession::query()
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->first();

            if ($session === null) {
                throw new ApiException('AI agent session not found.', 404);
            }

            if ($session->status === AgentSessionStatus::Closed) {
                throw new ApiException('This AI agent session is closed.', 422);
            }

            return $session;
        }

        return AIAgentSession::query()->create([
            'user_id' => $user->id,
            'status' => AgentSessionStatus::Active,
            'context' => [],
        ]);
    }

    private function storeMessage(
        AIAgentSession $session,
        AgentMessageRole $role,
        string $content,
        array $metadata = [],
    ): AIAgentMessage {
        return AIAgentMessage::query()->create([
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findAwaitingConfirmationAction(AIAgentSession $session): ?AIAgentAction
    {
        return AIAgentAction::query()
            ->where('session_id', $session->id)
            ->where('status', AgentActionStatus::AwaitingConfirmation)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $actionResult
     * @return array<string, mixed>
     */
    private function formatMessageResponseFromActionResult(
        AIAgentSession $session,
        array $actionResult,
        bool $cancelled = false,
    ): array {
        $action = $actionResult['action'] ?? [];
        $session->refresh();

        $response = [
            'session_id' => $session->id,
            'language' => $this->localeContext->getLocale(),
            'locale' => $this->localeContext->getLocale(),
            'text_direction' => $this->localeContext->getTextDirection(),
            'reply' => (string) ($actionResult['reply'] ?? ''),
            'intent' => $session->current_intent ?? AgentIntent::GeneralHelp->value,
            'confidence' => 1.0,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'pending_action' => null,
            'action_confirmed' => ! $cancelled,
            'action_cancelled' => $cancelled,
            'executed_action' => $cancelled ? null : $action,
        ];

        if (! $cancelled && isset($actionResult['result'])) {
            $response['result'] = $actionResult['result'];
            $this->rememberLastDiscussedApplication($session, $actionResult['result']);
        }

        if (! $cancelled && isset($action['name'])) {
            $response['intent'] = match ($action['name']) {
                'get_application_status' => AgentIntent::GetApplicationStatus->value,
                'get_application_next_step' => AgentIntent::GetApplicationNextStep->value,
                'get_required_documents' => AgentIntent::GetRequiredDocuments->value,
                'get_application_fee' => AgentIntent::GetApplicationFee->value,
                'get_payment_status' => AgentIntent::GetPaymentStatus->value,
                'get_fines' => AgentIntent::GetFines->value,
                'get_licenses' => AgentIntent::GetLicenses->value,
                'get_profile_status' => AgentIntent::GetProfileStatus->value,
                'get_available_tests' => AgentIntent::GetAvailableTests->value,
                'get_appointment_slots' => AgentIntent::GetAppointmentSlots->value,
                'get_current_appointments' => AgentIntent::GetCurrentAppointments->value,
                'book_appointment' => AgentIntent::BookAppointment->value,
                'get_test_results' => AgentIntent::GetTestResults->value,
                default => $session->current_intent ?? AgentIntent::GeneralHelp->value,
            };
            $session->current_intent = $response['intent'];
            $session->save();
        }

        if (! $cancelled
            && ($action['name'] ?? null) === 'get_current_appointments'
            && empty($actionResult['result']['appointments'] ?? [])) {
            $response['suggested_next_actions'] = ['get_available_tests', 'get_appointment_slots'];
        }

        return $this->responseLocale->decorate(AgentTranslator::localizePayload($response));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function rememberLastDiscussedApplication(AIAgentSession $session, array $result): void
    {
        $applicationId = null;
        foreach (['application_id', 'id'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) {
                $applicationId = (int) $result[$key];
                break;
            }
        }

        if ($applicationId === null) {
            return;
        }

        $context = $session->context ?? [];
        $context['last_application_id'] = $applicationId;

        if (isset($result['appointment_id']) && is_numeric($result['appointment_id'])) {
            $context['last_appointment_id'] = (int) $result['appointment_id'];
        } elseif (isset($result['id']) && is_numeric($result['id']) && isset($result['test_type'])) {
            $context['last_appointment_id'] = (int) $result['id'];
        }

        if (isset($result['test_type']['code']) && is_string($result['test_type']['code'])) {
            $context['last_test_type_code'] = $result['test_type']['code'];
        }

        $session->context = $context;
        $session->save();
    }

    private function persistProposedAction(User $user, AIAgentSession $session, array $payload): ?AIAgentAction
    {
        $proposed = $payload['proposed_action'] ?? null;

        if (! is_array($proposed) || empty($proposed['name'])) {
            return $this->findAwaitingConfirmationAction($session);
        }

        if (! empty($payload['missing_slots'])) {
            return $this->findAwaitingConfirmationAction($session);
        }

        $existing = $this->findAwaitingConfirmationAction($session);
        if ($existing !== null) {
            return $existing;
        }

        $requiresConfirmation = (bool) ($payload['requires_confirmation'] ?? config('ai.require_confirmation'));

        if (AgentSafetyRules::isReadOnlyAction((string) $proposed['name'])
            && empty($payload['missing_slots'])
            && ($payload['execute_immediately'] ?? true) !== false) {
            $requiresConfirmation = false;
        }

        $status = $requiresConfirmation
            ? AgentActionStatus::AwaitingConfirmation
            : AgentActionStatus::Pending;

        return AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $user->id,
            'action_name' => (string) $proposed['name'],
            'arguments' => $proposed['arguments'] ?? [],
            'status' => $status,
            'requires_confirmation' => $requiresConfirmation,
            'confirmation_message' => (string) ($payload['reply'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessageResponse(
        AIAgentSession $session,
        array $payload,
        ?AIAgentAction $pendingAction,
    ): array {
        $response = [
            'session_id' => $session->id,
            'language' => $this->localeContext->getLocale(),
            'locale' => $this->localeContext->getLocale(),
            'text_direction' => $this->localeContext->getTextDirection(),
            'reply' => $payload['reply'],
            'intent' => $payload['intent'],
            'confidence' => $payload['confidence'],
            'missing_slots' => $payload['missing_slots'] ?? [],
            'requires_confirmation' => (bool) ($payload['requires_confirmation'] ?? false),
            'pending_action' => null,
            'suggested_next_actions' => array_values($payload['suggested_next_actions'] ?? []),
        ];

        if (isset($payload['message_type'])) {
            $response['message_type'] = $payload['message_type'];
        }

        if (isset($payload['ui_payload']) && is_array($payload['ui_payload'])) {
            $response['ui_payload'] = $payload['ui_payload'];
        }

        if (isset($payload['application']) && is_array($payload['application'])) {
            $response['application'] = $payload['application'];
        }

        if ($pendingAction !== null) {
            $response['pending_action'] = [
                'id' => $pendingAction->id,
                'name' => $pendingAction->action_name,
                'arguments' => $pendingAction->arguments ?? [],
                'requires_confirmation' => $pendingAction->requires_confirmation,
                'status' => $pendingAction->status->value,
            ];
            $response['requires_confirmation'] = $pendingAction->requires_confirmation;
        }

        return $this->responseLocale->decorate($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyReadOnlyConfirmationDefaults(array $payload): array
    {
        $proposed = $payload['proposed_action'] ?? null;

        if (is_array($proposed)
            && AgentSafetyRules::isReadOnlyAction((string) ($proposed['name'] ?? ''))
            && empty($payload['missing_slots'])
            && ($payload['execute_immediately'] ?? true) !== false) {
            $payload['requires_confirmation'] = false;
        }

        return $payload;
    }

    /**
     * Execute read-only actions immediately (status, fines, licenses, documents list).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function maybeExecuteReadOnlyAction(
        User $user,
        AIAgentSession $session,
        ?AIAgentAction $pendingAction,
        array $payload,
    ): ?array {
        if ($pendingAction === null || $pendingAction->requires_confirmation) {
            return null;
        }

        if (! AgentSafetyRules::isReadOnlyAction($pendingAction->action_name)) {
            return null;
        }

        if (! empty($payload['missing_slots'])) {
            return null;
        }

        if (($payload['execute_immediately'] ?? true) === false) {
            return null;
        }

        try {
            return $this->formatMessageResponseFromActionResult(
                $session,
                $this->actionService->executeReadOnlyNow($user, $pendingAction->id)
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
