<?php

namespace App\Modules\AIAgent\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AIAgent\Requests\SendAgentMessageRequest;
use App\Modules\AIAgent\Requests\UploadAgentDocumentRequest;
use App\Modules\AIAgent\Resources\AIAgentSessionResource;
use App\Modules\AIAgent\Services\AIAgentActionService;
use App\Modules\AIAgent\Services\AgentDocumentUploadService;
use App\Modules\AIAgent\Services\AIAgentService;
use Illuminate\Http\Request;

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

    public function uploadSessionDocument(
        UploadAgentDocumentRequest $request,
        int $session,
        AgentDocumentUploadService $uploadService
    ) {
        $validated = $request->validated();

        $data = $uploadService->upload(
            $request->user(),
            $session,
            (int) $validated['application_id'],
            (int) $validated['required_document_id'],
            $request->file('file')
        );

        return $this->successResponse($data, 'messages.documents.uploaded');
    }
}
