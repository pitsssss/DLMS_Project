<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Dashboard\Requests\EmployeeSessions\HeartbeatEmployeeSessionRequest;
use App\Modules\Dashboard\Requests\EmployeeSessions\ListEmployeeSessionsRequest;
use App\Modules\Dashboard\Requests\EmployeeSessions\RevokeAllEmployeeSessionsRequest;
use App\Modules\Dashboard\Requests\EmployeeSessions\RevokeEmployeeSessionRequest;
use App\Modules\Dashboard\Resources\DashboardEmployeeSessionAuditLogResource;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionLastSeenService;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionService;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionStatusResolver;
use Illuminate\Http\Request;

class DashboardEmployeeSessionController extends Controller
{
    public function index(ListEmployeeSessionsRequest $request, EmployeeSessionService $sessions)
    {
        $filters = $request->filters();
        $paginator = $sessions->paginate($filters, $request->user());

        $items = collect($paginator->items())->map(
            fn ($session) => $sessions->presentSession($session, $request->user(), detailed: false)
        )->values()->all();

        return $this->successResponse([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.employee_sessions.list_retrieved');
    }

    public function stats(ListEmployeeSessionsRequest $request, EmployeeSessionService $sessions)
    {
        return $this->successResponse(
            $sessions->stats($request->filters()),
            'messages.employee_sessions.stats_retrieved'
        );
    }

    public function options(EmployeeSessionService $sessions)
    {
        return $this->successResponse(
            $sessions->options(),
            'messages.employee_sessions.options_retrieved'
        );
    }

    public function show(string $session, EmployeeSessionService $sessions, Request $request)
    {
        $model = $sessions->findForManagement($session);

        return $this->successResponse(
            $sessions->presentSession($model, $request->user(), detailed: true),
            'messages.employee_sessions.details_retrieved'
        );
    }

    public function revoke(
        string $session,
        RevokeEmployeeSessionRequest $request,
        EmployeeSessionService $sessions
    ) {
        $model = $sessions->findForManagement($session);
        $updated = $sessions->revokeOne($model, $request->user(), $request->validated(), $request);

        return $this->successResponse(
            $sessions->presentSession($updated, $request->user(), detailed: true),
            'messages.employee_sessions.revoked'
        );
    }

    public function revokeAll(
        int $employee,
        RevokeAllEmployeeSessionsRequest $request,
        EmployeeSessionService $sessions
    ) {
        $target = $sessions->assertDashboardEmployee(User::query()->findOrFail($employee));
        $result = $sessions->revokeAllForEmployee($target, $request->user(), $request->validated(), $request);

        return $this->successResponse([
            'employee' => [
                'id' => $result['employee']->id,
                'name' => $result['employee']->name,
                'email' => $result['employee']->email,
            ],
            'targeted_session_count' => $result['targeted'],
            'revoked_session_count' => $result['revoked'],
            'already_ended_count' => $result['already_ended'],
            'preserved_current_session_count' => $result['preserved_current'],
        ], 'messages.employee_sessions.revoked_all');
    }

    public function employeeSessions(
        int $employee,
        ListEmployeeSessionsRequest $request,
        EmployeeSessionService $sessions
    ) {
        $target = $sessions->assertDashboardEmployee(User::query()->findOrFail($employee));
        $paginator = $sessions->paginateForEmployee($target, $request->filters(), $request->user());

        $items = collect($paginator->items())->map(
            fn ($session) => $sessions->presentSession($session, $request->user(), detailed: false)
        )->values()->all();

        return $this->successResponse([
            'employee' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
            ],
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.employee_sessions.employee_history_retrieved');
    }

    public function auditLogs(string $session, Request $request, EmployeeSessionService $sessions)
    {
        $model = $sessions->findForManagement($session);
        $perPage = min(
            max(1, (int) $request->query('per_page', config('employee_sessions.default_per_page', 20))),
            (int) config('employee_sessions.max_per_page', 100)
        );
        $paginator = $sessions->auditLogsForSession($model, $perPage);

        return $this->successResponse([
            'items' => DashboardEmployeeSessionAuditLogResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.employee_sessions.audit_logs_retrieved');
    }

    public function heartbeat(
        HeartbeatEmployeeSessionRequest $request,
        EmployeeSessionLastSeenService $lastSeen,
        EmployeeSessionStatusResolver $statusResolver,
    ) {
        if (! config('employee_sessions.heartbeat_enabled', true)) {
            return $this->errorResponse('messages.employee_sessions.heartbeat_disabled', [], 403);
        }

        $result = $lastSeen->touchCurrentSession($request->user(), $request, force: false);
        $session = $result['session'];

        if ($session === null) {
            return $this->errorResponse('messages.employee_sessions.current_session_missing', [], 404);
        }

        $status = $statusResolver->resolve($session);

        return $this->successResponse([
            'server_time' => now()->toIso8601String(),
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ], 'messages.employee_sessions.heartbeat_ok');
    }
}
