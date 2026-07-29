<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\LicenseApplication;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\Applications\Services\ApplicationDocumentService;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use Illuminate\Support\Facades\DB;

class AIAgentActionService
{
    public function __construct(
        private readonly AgentActionExecutor $executor,
        private readonly AgentActionReplyBuilder $replyBuilder,
        private readonly AgentApplicationActionPolicy $applicationPolicy,
        private readonly ApplicationDocumentService $documents,
        private readonly AgentEvaluationService $evaluationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function confirm(User $user, int $actionId): array
    {
        $action = $this->resolveOwnedAction($user, $actionId);

        if (AgentSafetyRules::isAdminOnlyAction($action->action_name)) {
            $this->markFailed($action, 'This action requires an authorized employee.');

            throw new ApiException('This action requires an authorized employee.', 403);
        }

        $this->assertAwaitingConfirmation($action);

        if (! AgentSafetyRules::isPhase9bExecutable($action->action_name)) {
            throw new ApiException('This action cannot be executed yet. Please use the standard API endpoints.', 422);
        }

        try {
            $this->assertMutatingActionStillAllowed($user, $action);

            $action->status = AgentActionStatus::Confirmed;
            $action->confirmed_at = now();
            $action->save();

            $result = $this->executor->execute($user, $action);

            $action->status = AgentActionStatus::Executed;
            $action->executed_at = now();
            $action->result = $result;
            $action->error_message = null;
            $action->save();

            $reply = $this->replyBuilder->success($action, $result);
            $message = $this->storeAssistantMessage($action, $reply, [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'outcome' => 'executed',
            ]);

            $this->recordActionEvaluation($action, $message, true);

            return $this->formatConfirmResponse($action, $result, $reply);
        } catch (ApiException $e) {
            $this->markFailed($action, $e->getMessage());
            $this->storeAssistantMessage($action, $this->replyBuilder->failure($e->getMessage()), [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'outcome' => 'failed',
            ]);
            $this->recordActionEvaluation($action, null, false, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Execute a read-only action that was created without requiring confirmation.
     *
     * @return array<string, mixed>
     */
    public function executeReadOnlyNow(User $user, int $actionId): array
    {
        $action = $this->resolveOwnedAction($user, $actionId);

        if ($action->requires_confirmation) {
            throw new ApiException('This action requires confirmation before execution.', 422);
        }

        if (! AgentSafetyRules::isReadOnlyAction($action->action_name)) {
            throw new ApiException('This action cannot be executed without confirmation.', 422);
        }

        if ($action->status === AgentActionStatus::Executed) {
            throw new ApiException('This action has already been executed.', 422);
        }

        try {
            $result = $this->executor->execute($user, $action);

            $action->status = AgentActionStatus::Executed;
            $action->executed_at = now();
            $action->result = $result;
            $action->error_message = null;
            $action->save();

            $reply = $this->replyBuilder->success($action, $result);
            $message = $this->storeAssistantMessage($action, $reply, [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'outcome' => 'executed',
            ]);

            $this->recordActionEvaluation($action, $message, true);

            return $this->formatConfirmResponse($action, $result, $reply);
        } catch (ApiException $e) {
            $this->markFailed($action, $e->getMessage());
            $this->storeAssistantMessage($action, $this->replyBuilder->failure($e->getMessage()), [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'outcome' => 'failed',
            ]);
            $this->recordActionEvaluation($action, null, false, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $actionId): array
    {
        return DB::transaction(function () use ($user, $actionId) {
            $action = $this->resolveOwnedAction($user, $actionId);

            $this->assertCancellable($action);

            $action->status = AgentActionStatus::Cancelled;
            $action->save();

            $reply = $this->replyBuilder->cancel();
            $message = $this->storeAssistantMessage($action, $reply, [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'outcome' => 'cancelled',
            ]);

            $this->recordActionEvaluation($action, $message, true, null, 'cancelled');

            return [
                'action' => $this->actionSummary($action),
                'reply' => $reply,
            ];
        });
    }

    private function resolveOwnedAction(User $user, int $actionId): AIAgentAction
    {
        $action = AIAgentAction::query()
            ->whereKey($actionId)
            ->where('user_id', $user->id)
            ->first();

        if ($action === null) {
            throw new ApiException('AI agent action not found.', 404);
        }

        return $action;
    }

    private function assertAwaitingConfirmation(AIAgentAction $action): void
    {
        if ($action->status === AgentActionStatus::AwaitingConfirmation) {
            return;
        }

        $message = match ($action->status) {
            AgentActionStatus::Executed => 'This action has already been executed.',
            AgentActionStatus::Cancelled => 'This action has been cancelled.',
            AgentActionStatus::Failed => 'This action has failed and cannot be confirmed.',
            AgentActionStatus::Confirmed => 'This action is already being processed.',
            default => 'This action is not awaiting confirmation.',
        };

        throw new ApiException($message, 422);
    }

    /**
     * For confirmed mutating actions, validate against the current DB state.
     *
     * @throws ApiException
     */
    private function assertMutatingActionStillAllowed(User $user, AIAgentAction $action): void
    {
        if (! in_array($action->action_name, [
            'start_payment',
            'book_appointment',
            'submit_documents_for_review',
        ], true)) {
            return;
        }

        $applicationId = $action->arguments['application_id'] ?? null;
        if (! is_numeric($applicationId) || (int) $applicationId < 1) {
            throw new ApiException('Application ID is required for this action.', 422);
        }

        $application = LicenseApplication::query()
            ->whereKey((int) $applicationId)
            ->where('citizen_id', $user->id)
            ->with(['serviceType'])
            ->first();

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        $blockReason = $this->applicationPolicy->blockReason($application, $action->action_name);
        if ($blockReason !== null) {
            throw new ApiException($blockReason, 422);
        }

        if ($action->action_name === 'submit_documents_for_review') {
            $checklist = $this->documents->requiredChecklist($user, (int) $applicationId);

            $required = array_values(array_filter(
                $checklist,
                static fn (array $item): bool => ($item['is_required'] ?? false) === true
            ));

            $missing = [];
            $rejected = [];

            foreach ($required as $item) {
                $latest = $item['latest_document'] ?? null;
                $name = (string) ($item['name'] ?? '');

                if ($latest === null) {
                    $missing[] = $name;
                    continue;
                }

                $status = strtolower((string) ($latest['status'] ?? ''));
                if ($status === 'rejected') {
                    $rejected[] = $name;
                }
            }

            if ($missing !== []) {
                $missingList = implode('، ', array_values(array_filter($missing)));
                throw new ApiException(
                    "لا يمكن إرسال الوثائق للمراجعة لأن الوثائق المطلوبة غير مكتملة: {$missingList}.",
                    422
                );
            }

            if ($rejected !== []) {
                $rejectedList = implode('، ', array_values(array_filter($rejected)));
                throw new ApiException(
                    "لا يمكن إرسال الوثائق للمراجعة لأن بعض الوثائق مرفوضة. يرجى إعادة رفع: {$rejectedList}.",
                    422
                );
            }
        }
    }

    private function assertCancellable(AIAgentAction $action): void
    {
        if (in_array($action->status, [
            AgentActionStatus::AwaitingConfirmation,
            AgentActionStatus::Pending,
        ], true)) {
            return;
        }

        throw new ApiException('This action cannot be cancelled.', 422);
    }

    private function markFailed(AIAgentAction $action, string $errorMessage): void
    {
        $action->status = AgentActionStatus::Failed;
        $action->error_message = $errorMessage;
        $action->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function formatConfirmResponse(AIAgentAction $action, array $result, string $reply): array
    {
        return [
            'action' => $this->actionSummary($action),
            'result' => $result,
            'reply' => $reply,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionSummary(AIAgentAction $action): array
    {
        return [
            'id' => $action->id,
            'name' => $action->action_name,
            'status' => $action->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function storeAssistantMessage(AIAgentAction $action, string $reply, array $metadata): AIAgentMessage
    {
        $message = AIAgentMessage::query()->create([
            'session_id' => $action->session_id,
            'role' => AgentMessageRole::Assistant,
            'content' => $reply,
            'metadata' => $metadata,
        ]);

        AIAgentSession::query()
            ->whereKey($action->session_id)
            ->update(['last_message_at' => now()]);

        return $message;
    }

    private function recordActionEvaluation(
        AIAgentAction $action,
        ?AIAgentMessage $message,
        bool $success,
        ?string $error = null,
        string $outcome = 'executed',
    ): void {
        $session = AIAgentSession::query()->find($action->session_id);

        if ($session === null) {
            return;
        }

        $this->evaluationService->recordActionOutcome(
            $session,
            $message,
            $action,
            $success,
            $outcome,
            $error
        );
    }
}
