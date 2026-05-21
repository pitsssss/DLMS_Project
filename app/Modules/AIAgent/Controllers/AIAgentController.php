<?php

namespace App\Modules\AIAgent\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AIAgent\Requests\SendAgentMessageRequest;
use App\Modules\AIAgent\Resources\AIAgentSessionResource;
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

        return $this->successResponse($data, 'AI agent response generated successfully.');
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
        ], 'AI agent sessions retrieved successfully.');
    }

    public function showSession(Request $request, int $session, AIAgentService $agent)
    {
        $model = $agent->getSessionForUser($request->user(), $session);

        return $this->successResponse(
            new AIAgentSessionResource($model),
            'AI agent session retrieved successfully.'
        );
    }
}
