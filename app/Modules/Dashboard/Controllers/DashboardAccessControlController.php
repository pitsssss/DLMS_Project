<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Requests\AccessControl\ArchiveAccessRoleRequest;
use App\Modules\Dashboard\Requests\AccessControl\CreateAccessRoleRequest;
use App\Modules\Dashboard\Requests\AccessControl\ListAccessPermissionsRequest;
use App\Modules\Dashboard\Requests\AccessControl\ListAccessRolesRequest;
use App\Modules\Dashboard\Requests\AccessControl\SyncEmployeeDirectPermissionsRequest;
use App\Modules\Dashboard\Requests\AccessControl\SyncEmployeeRoleRequest;
use App\Modules\Dashboard\Requests\AccessControl\SyncRolePermissionsRequest;
use App\Modules\Dashboard\Requests\AccessControl\UpdateAccessRoleRequest;
use App\Modules\Dashboard\Services\DashboardAccessControlService;
use Illuminate\Http\Request;

class DashboardAccessControlController extends Controller
{
    public function overview(DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->overview(),
            'messages.access_control.overview_retrieved'
        );
    }

    public function permissions(ListAccessPermissionsRequest $request, DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->listPermissions($request->validated()),
            'messages.access_control.permissions_retrieved'
        );
    }

    public function roles(ListAccessRolesRequest $request, DashboardAccessControlService $access)
    {
        $paginator = $access->paginateRoles($request->validated());

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn (Role $role) => $access->presentRole($role))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.access_control.roles_retrieved');
    }

    public function roleOptions(DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->roleOptions(),
            'messages.access_control.role_options_retrieved'
        );
    }

    public function showRole(Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        return $this->successResponse(
            $access->roleDetails($role),
            'messages.access_control.role_retrieved'
        );
    }

    public function storeRole(CreateAccessRoleRequest $request, DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->createRole($request->user(), $request->validated()),
            'messages.access_control.role_created'
        );
    }

    public function updateRole(UpdateAccessRoleRequest $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        return $this->successResponse(
            $access->updateRole($request->user(), $role, $request->validated()),
            'messages.access_control.role_updated'
        );
    }

    public function syncRolePermissions(SyncRolePermissionsRequest $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        return $this->successResponse(
            $access->syncRolePermissions($request->user(), $role, $request->validated()),
            'messages.access_control.role_permissions_updated'
        );
    }

    public function archiveRole(ArchiveAccessRoleRequest $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        return $this->successResponse(
            $access->archiveRole($request->user(), $role, $request->validated()),
            'messages.access_control.role_archived'
        );
    }

    public function restoreRole(Request $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        return $this->successResponse(
            $access->restoreRole($request->user(), $role),
            'messages.access_control.role_restored'
        );
    }

    public function roleEmployees(Request $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        $paginator = $access->roleEmployees($role, (int) $request->integer('per_page', 20));

        return $this->successResponse([
            'items' => collect($paginator->items())->map(static fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'user_type' => $user->user_type?->value,
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.access_control.role_employees_retrieved');
    }

    public function roleAuditLogs(Request $request, Role $role, DashboardAccessControlService $access)
    {
        if ($role->name === 'citizen') {
            abort(404);
        }

        $paginator = $access->roleAuditLogs($role, (int) $request->integer('per_page', 20));

        return $this->successResponse([
            'items' => collect($paginator->items())->map(static fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => __('messages.access_control.audit_actions.'.$log->action),
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.access_control.audit_logs_retrieved');
    }

    public function employeeAccess(User $employee, DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->employeeAccess($employee),
            'messages.access_control.employee_access_retrieved'
        );
    }

    public function syncEmployeeRole(SyncEmployeeRoleRequest $request, User $employee, DashboardAccessControlService $access)
    {
        return $this->successResponse(
            $access->syncEmployeeRole($request->user(), $employee, $request->validated()),
            'messages.access_control.employee_role_updated'
        );
    }

    public function syncDirectPermissions(
        SyncEmployeeDirectPermissionsRequest $request,
        User $employee,
        DashboardAccessControlService $access
    ) {
        return $this->successResponse(
            $access->syncDirectPermissions($request->user(), $employee, $request->validated()),
            'messages.access_control.employee_direct_permissions_updated'
        );
    }

    public function employeeAccessAuditLogs(Request $request, User $employee, DashboardAccessControlService $access)
    {
        $paginator = $access->employeeAccessAuditLogs($employee, (int) $request->integer('per_page', 20));

        return $this->successResponse([
            'items' => collect($paginator->items())->map(static fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => __('messages.access_control.audit_actions.'.$log->action),
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.access_control.audit_logs_retrieved');
    }
}
