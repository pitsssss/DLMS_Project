<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Resources\DashboardRoleResource;
use App\Modules\Dashboard\Services\DashboardRoleService;

class DashboardRoleController extends Controller
{
    public function index(DashboardRoleService $roles)
    {
        return $this->successResponse(
            DashboardRoleResource::collection($roles->listRoles())->resolve(),
            'messages.dashboard.roles_retrieved'
        );
    }

    public function show(string $role, DashboardRoleService $roles)
    {
        return $this->successResponse(
            new DashboardRoleResource($roles->getRole($role)),
            'messages.dashboard.role_retrieved'
        );
    }

    public function permissions(DashboardRoleService $roles)
    {
        return $this->successResponse(
            $roles->listPermissions()->pluck('name')->values()->all(),
            'messages.dashboard.permissions_retrieved'
        );
    }
}
