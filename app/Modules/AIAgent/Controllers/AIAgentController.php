<?php

namespace App\Modules\AIAgent\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\AIAgent\Requests\HandleAgentInteractionRequest;
use App\Modules\AIAgent\Requests\SendAgentMessageRequest;
use App\Modules\AIAgent\Requests\UploadAgentDocumentRequest;
use App\Modules\AIAgent\Resources\AIAgentSessionResource;
use App\Modules\AIAgent\Services\AIAgentActionService;
use App\Modules\AIAgent\Services\AgentDocumentFlowService;
use App\Modules\AIAgent\Services\AgentDocumentUploadService;
use App\Modules\AIAgent\Services\AgentPendingWorkflowService;
use App\Modules\AIAgent\Services\AIAgentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class AIAgentController extends Controller
{
    public function sendMessage(SendAgentMessageRequest $request, AIAgentService $agent)
    {
        $data = $agent->handleMessage(
            $request->user(),
            $request->validated('message'),
            $request->validated('session_id')
        );

        return $this->successResponse($data, 'messages.ai_agent.response_generated');
    }

    public function listSessions(Request $request, AIAgentService $agent)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $agent->listSessions(
            $request->user(),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items' => AIAgentSessionResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.ai_agent.sessions_list');
    }

    public function showSession(Request $request, int $session, AIAgentService $agent)
    {
        $model = $agent->getSessionForUser($request->user(), $session);

        return $this->successResponse(
            new AIAgentSessionResource($model),
            'messages.ai_agent.session_retrieved'
        );
    }

    public function confirmAction(Request $request, int $action, AIAgentActionService $actions)
    {
        $data = $actions->confirm($request->user(), $action);

        return $this->successResponse($data, 'messages.ai_agent.action_executed');
    }

    public function cancelAction(Request $request, int $action, AIAgentActionService $actions)
    {
        $data = $actions->cancel($request->user(), $action);

        return $this->successResponse($data, 'messages.ai_agent.action_cancelled');
    }

    public function handleInteraction(
        HandleAgentInteractionRequest $request,
        int $session,
        AIAgentService $agent,
        AgentDocumentFlowService $documentFlow,
        AgentPendingWorkflowService $pendingWorkflow,
        AIAgentActionService $actions,
    ) {
        $sessionModel = $agent->getSessionForUser($request->user(), $session);
        $validated = $request->validated();
        $action = (string) $validated['action'];
        $token = isset($validated['selection_token']) ? (string) $validated['selection_token'] : null;

        $documentState = \App\Modules\AIAgent\Enums\DocumentFlowState::tryFrom(
            (string) (($sessionModel->context['document_flow']['state'] ?? ''))
        );

        if ($action === 'cancel_pending_workflow') {
            $data = $pendingWorkflow->cancelPending($request->user(), $sessionModel);

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'show_application_choices_again') {
            $data = $pendingWorkflow->showChoicesAgain($request->user(), $sessionModel);

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'select_application' && $documentState !== \App\Modules\AIAgent\Enums\DocumentFlowState::ApplicationSelection) {
            $data = $pendingWorkflow->selectApplicationByToken(
                $request->user(),
                $sessionModel,
                (string) $token
            );

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'select_appointment_slot') {
            $data = $pendingWorkflow->selectAppointmentSlotByToken(
                $request->user(),
                $sessionModel,
                (string) $token
            );

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'select_appointment') {
            $data = $pendingWorkflow->selectAppointmentByToken(
                $request->user(),
                $sessionModel,
                (string) $token
            );

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'select_license') {
            $data = $pendingWorkflow->selectLicenseByToken(
                $request->user(),
                $sessionModel,
                (string) $token
            );

            return $this->successResponse($data, 'messages.ai_agent.response_generated');
        }

        if ($action === 'confirm_pending_action') {
            $actionId = (int) ($validated['action_id'] ?? 0);
            $data = $actions->confirm($request->user(), $actionId);

            return $this->successResponse($data, 'messages.ai_agent.action_executed');
        }

        if ($action === 'cancel_pending_action') {
            $actionId = (int) ($validated['action_id'] ?? 0);
            $data = $actions->cancel($request->user(), $actionId);

            return $this->successResponse($data, 'messages.ai_agent.action_cancelled');
        }

        $data = $documentFlow->handleInteraction(
            $request->user(),
            $sessionModel,
            $action,
            $token
        );

        return $this->successResponse($data, 'messages.ai_agent.response_generated');
    }

    public function uploadSessionDocument(
        UploadAgentDocumentRequest $request,
        int $session,
        AgentDocumentUploadService $uploadService,
        AgentDocumentFlowService $documentFlow,
        AIAgentService $agent,
    ) {
        $files = $this->flattenUploadedFiles($request->allFiles());

        if ($request->isTokenMode()) {
            $sessionModel = $agent->getSessionForUser($request->user(), $session);
            $data = $documentFlow->uploadWithToken(
                $request->user(),
                $sessionModel,
                (string) $request->input('upload_token'),
                $files,
                $request->filled('application_id') ? (int) $request->input('application_id') : null,
                $request->filled('required_document_id') ? (int) $request->input('required_document_id') : null,
            );

            return $this->successResponse($data, 'messages.documents.uploaded');
        }

        if (count($files) === 0) {
            throw new ApiException(
                'يرجى إرفاق ملف الوثيقة المطلوبة.',
                422,
                [],
                [],
                'DOCUMENT_FILE_REQUIRED'
            );
        }

        if (count($files) > 1) {
            throw new ApiException(
                'تم إرفاق أكثر من ملف. يرجى إرفاق ملف واحد فقط.',
                422,
                [],
                [],
                'EXACTLY_ONE_DOCUMENT_FILE_REQUIRED',
                [
                    'received_files_count' => count($files),
                    'maximum_files' => 1,
                ]
            );
        }

        $validated = $request->validated();

        $data = $uploadService->upload(
            $request->user(),
            $session,
            (int) $validated['application_id'],
            (int) $validated['required_document_id'],
            $files[0]
        );

        return $this->successResponse($data, 'messages.documents.uploaded');
    }

    /**
     * Recursively collect every UploadedFile from multipart payload.
     *
     * @param  array<string, mixed>  $allFiles
     * @return list<UploadedFile>
     */
    private function flattenUploadedFiles(array $allFiles): array
    {
        $files = [];

        $walker = function (mixed $value) use (&$files, &$walker): void {
            if ($value instanceof UploadedFile) {
                $files[] = $value;

                return;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $walker($item);
                }
            }
        };

        $walker($allFiles);

        return $files;
    }
}
