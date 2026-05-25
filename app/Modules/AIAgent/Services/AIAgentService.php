<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handleMessage(User $user, string $message, ?int $sessionId = null): array
    {
        if (! config('ai.enabled')) {
            throw new ApiException('messages.ai_agent.disabled', 503);
        }

        $prepared = $this->preProcessor->process($message);
        if ($prepared['flags']['empty']) {
            throw new ApiException('messages.ai_agent.message_empty', 422, ['message' => [__('messages.ai_agent.message_required')]]);
        }

        return DB::transaction(function () use ($user, $prepared, $sessionId) {
            $session = $this->resolveSession($user, $sessionId);
            $userMessage = $prepared['message'];

            $state = $this->sessionContext->mergeUserMessage($session, $userMessage);

            $this->storeMessage($session, AgentMessageRole::User, $userMessage);

            $startedAt = hrtime(true);
            $wasFallback = false;
            $payload = null;

            try {
                $contents = $this->contextBuilder->buildGeminiContents($session);
                $raw = $this->geminiClient->generateStructuredResponse(
                    $this->contextBuilder->buildSystemInstruction($session),
                    $contents
                );
                $payload = $this->postProcessor->normalize(
                    $raw,
                    $userMessage,
                    $prepared['language_hint']
                );
            } catch (\Throwable) {
                $payload = null;
            }

            if ($payload === null) {
                $wasFallback = true;
                $payload = $this->intentDetector->detectFallback(
                    $userMessage,
                    $session,
                    $prepared['language_hint']
                );
            }

            $payload = $this->slotFiller->apply($session, $payload, $userMessage, $state);
            $payload = $this->postProcessor->applyConfirmationReply($payload);
            $state = $this->sessionContext->finalizeState($state, $payload, $userMessage);

            $pendingAction = $this->persistProposedAction($user, $session, $payload);

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

            $session->current_intent = $payload['intent'];
            $session->last_message_at = now();
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
                throw new ApiException('messages.ai_agent.session_not_found', 404);
            }

            if ($session->status === AgentSessionStatus::Closed) {
                throw new ApiException('messages.ai_agent.session_closed', 422);
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
    private function persistProposedAction(User $user, AIAgentSession $session, array $payload): ?AIAgentAction
    {
        $proposed = $payload['proposed_action'] ?? null;

        if (! is_array($proposed) || empty($proposed['name'])) {
            return null;
        }

        if (! empty($payload['missing_slots'])) {
            return null;
        }

        $requiresConfirmation = (bool) ($payload['requires_confirmation'] ?? config('ai.require_confirmation'));

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
            'reply' => $payload['reply'],
            'intent' => $payload['intent'],
            'confidence' => $payload['confidence'],
            'missing_slots' => $payload['missing_slots'] ?? [],
            'requires_confirmation' => (bool) ($payload['requires_confirmation'] ?? false),
            'pending_action' => null,
        ];

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

        return $response;
    }
}
