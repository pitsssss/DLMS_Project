<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AuditLogs\Repositories\AuditLogRepository;
use App\Modules\AuditLogs\Resources\AuditLogResource;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogRepository $auditLogs)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:128'],
            'entity_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $paginator = $auditLogs->paginate(
            (int) ($validated['per_page'] ?? 20),
            $validated['entity_type'] ?? null,
            isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            $validated['action'] ?? null,
            isset($validated['user_id']) ? (int) $validated['user_id'] : null
        );

        return $this->successResponse([
            'items' => AuditLogResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.audit.list');
    }
}
