<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class DashboardRoleService
{
    /**
     * @return Collection<int, Role>
     */
    public function listRoles(): Collection
    {
        return Role::query()
            ->where('name', '!=', 'citizen')
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    public function getRole(string $roleName): Role
    {
        return Role::query()
            ->where('name', $roleName)
            ->with('permissions')
            ->firstOrFail();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function listPermissions(): Collection
    {
        return Permission::query()->orderBy('name')->get();
    }
}
