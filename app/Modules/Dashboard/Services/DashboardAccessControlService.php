<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Support\PermissionRegistry;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DashboardAccessControlService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array<string, int>
     */
    public function overview(): array
    {
        $rolesBase = Role::query()->where('name', '!=', 'citizen');
        $employeesBase = User::query()
            ->whereIn('user_type', [UserType::Admin, UserType::Employee])
            ->whereNull('deleted_at');

        $recentlyChanged = AuditLog::query()
            ->whereIn('action', [
                'access_role.created',
                'access_role.updated',
                'access_role.permissions_updated',
                'access_role.archived',
                'access_role.restored',
                'employee.roles_updated',
                'employee.direct_permissions_updated',
                'employee.role_assigned',
                'document_reviewer.permissions_repaired',
            ])
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $permissionNames = PermissionRegistry::permissionNames();
        $sensitive = 0;
        $critical = 0;
        foreach ($permissionNames as $name) {
            $level = PermissionRegistry::riskLevel($name);
            if ($level === 'critical') {
                $critical++;
            } elseif ($level === 'sensitive') {
                $sensitive++;
            }
        }

        return [
            'total_roles' => (clone $rolesBase)->count(),
            'system_roles' => (clone $rolesBase)->where('is_system', true)->count(),
            'custom_roles' => (clone $rolesBase)->where('is_system', false)->count(),
            'active_assignable_roles' => (clone $rolesBase)
                ->where('is_assignable', true)
                ->where('is_archived', false)
                ->count(),
            'total_permissions' => Permission::query()->count(),
            'sensitive_permissions' => $sensitive,
            'critical_permissions' => $critical,
            'employees_with_roles' => (clone $employeesBase)->whereNotNull('role_id')->count(),
            'employees_without_roles' => (clone $employeesBase)->whereNull('role_id')->count(),
            'employees_with_direct_permissions' => (clone $employeesBase)
                ->whereHas('directPermissions')
                ->count(),
            'super_admin_count' => User::query()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereHas('role', fn (Builder $q) => $q->where('name', 'super_admin'))
                ->count(),
            'document_reviewer_count' => User::query()
                ->whereNull('deleted_at')
                ->whereIn('user_type', [UserType::Admin, UserType::Employee])
                ->whereHas('role', fn (Builder $q) => $q->where('name', 'profile_document_reviewer'))
                ->count(),
            'recently_changed_access_count' => $recentlyChanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{groups: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listPermissions(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $module = $filters['module'] ?? null;
        $risk = $filters['risk_level'] ?? null;
        $assignable = array_key_exists('assignable', $filters) ? filter_var($filters['assignable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        $dbPermissions = Permission::query()
            ->withCount(['roles', 'users'])
            ->orderBy('name')
            ->get()
            ->keyBy('name');

        $items = [];
        foreach (PermissionRegistry::permissions() as $meta) {
            $name = (string) $meta['name'];
            $row = $dbPermissions->get($name);

            if ($module !== null && $module !== '' && ($meta['module'] ?? null) !== $module) {
                continue;
            }
            if ($risk !== null && $risk !== '' && ($meta['risk_level'] ?? null) !== $risk) {
                continue;
            }
            if ($assignable !== null && (bool) ($meta['assignable'] ?? false) !== $assignable) {
                continue;
            }
            if ($search !== '') {
                $hay = Str::lower($name.' '.($meta['label'] ?? '').' '.($meta['description'] ?? ''));
                if (! str_contains($hay, Str::lower($search))) {
                    continue;
                }
            }

            $items[] = [
                'id' => $row?->id,
                'name' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'module' => $meta['module'],
                'module_label' => PermissionRegistry::moduleLabel((string) $meta['module']),
                'risk_level' => $meta['risk_level'],
                'risk_label' => PermissionRegistry::riskLabel((string) $meta['risk_level']),
                'assignable' => (bool) ($meta['assignable'] ?? false),
                'protected' => (bool) ($meta['protected'] ?? false),
                'roles_count' => (int) ($row?->roles_count ?? 0),
                'direct_employees_count' => (int) ($row?->users_count ?? 0),
                'registered_in_database' => $row !== null,
            ];
        }

        $groups = [];
        foreach ($items as $item) {
            $key = (string) $item['module'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'module' => $key,
                    'module_label' => $item['module_label'],
                    'permissions' => [],
                ];
            }
            $groups[$key]['permissions'][] = $item;
        }

        ksort($groups);

        return [
            'groups' => array_values($groups),
            'meta' => [
                'total' => count($items),
                'modules' => collect(PermissionRegistry::modules())
                    ->map(fn (array $m, string $key) => ['module' => $key, 'label' => $m['label']])
                    ->values()
                    ->all(),
                'risk_levels' => collect(PermissionRegistry::riskLevels())
                    ->map(fn (array $m, string $key) => ['risk_level' => $key, 'label' => $m['label']])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Role>
     */
    public function paginateRoles(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $query = Role::query()
            ->where('name', '!=', 'citizen')
            ->withCount(['permissions', 'users'])
            ->with('permissions');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('display_name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (array_key_exists('is_system', $filters) && $filters['is_system'] !== null && $filters['is_system'] !== '') {
            $query->where('is_system', filter_var($filters['is_system'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('is_assignable', $filters) && $filters['is_assignable'] !== null && $filters['is_assignable'] !== '') {
            $query->where('is_assignable', filter_var($filters['is_assignable'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('is_archived', $filters) && $filters['is_archived'] !== null && $filters['is_archived'] !== '') {
            $query->where('is_archived', filter_var($filters['is_archived'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['permission_module'])) {
            $module = (string) $filters['permission_module'];
            $names = collect(PermissionRegistry::permissions())
                ->where('module', $module)
                ->pluck('name')
                ->all();
            $query->whereHas('permissions', fn (Builder $q) => $q->whereIn('name', $names));
        }

        return $query->orderBy('is_system', 'desc')->orderBy('name')->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roleOptions(): array
    {
        return Role::query()
            ->where('name', '!=', 'citizen')
            ->where('is_archived', false)
            ->where('is_assignable', true)
            ->orderBy('display_name')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'is_system', 'is_protected'])
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->display_name ?: $role->name,
                'is_system' => (bool) $role->is_system,
                'is_protected' => (bool) $role->is_protected,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function roleDetails(Role $role): array
    {
        $role->loadMissing('permissions');
        $role->loadCount(['users', 'permissions']);

        return $this->presentRole($role, detailed: true);
    }

    /**
     * @param  array{name: string, display_name: string, description?: string|null, permission_ids?: list<int>}  $data
     * @return array<string, mixed>
     */
    public function createRole(User $actor, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);

        $name = Str::lower(trim((string) $data['name']));
        if (! preg_match('/^[a-z][a-z0-9_]{1,62}$/', $name)) {
            throw new ApiException(__('messages.access_control.invalid_role_name'), 422);
        }

        if (in_array($name, PermissionRegistry::reservedRoleNames(), true) || Role::query()->where('name', $name)->exists()) {
            throw new ApiException(__('messages.access_control.role_name_reserved_or_taken'), 422);
        }

        $permissionIds = array_values(array_unique(array_map('intval', $data['permission_ids'] ?? [])));
        $permissions = $this->resolveAssignablePermissions($permissionIds);

        $role = DB::transaction(function () use ($actor, $name, $data, $permissions) {
            $role = Role::query()->create([
                'name' => $name,
                'display_name' => trim((string) $data['display_name']),
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_protected' => false,
                'is_assignable' => true,
                'is_archived' => false,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($permissions->isNotEmpty()) {
                $role->permissions()->sync($permissions->pluck('id')->all());
            }

            $this->auditLogs->log(
                $actor,
                'access_role.created',
                'role',
                $role->id,
                null,
                [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'permission_names' => $permissions->pluck('name')->sort()->values()->all(),
                ]
            );

            return $role->fresh(['permissions']);
        });

        return $this->presentRole($role, detailed: true);
    }

    /**
     * @param  array{display_name?: string, description?: string|null, version: int}  $data
     * @return array<string, mixed>
     */
    public function updateRole(User $actor, Role $role, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);
        $this->assertRoleEditable($role);

        return DB::transaction(function () use ($actor, $role, $data) {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $data['version']);

            $old = [
                'display_name' => $locked->display_name,
                'description' => $locked->description,
                'version' => $locked->version,
            ];

            if ($locked->is_system) {
                // Machine name immutable; only label/description for non-protected.
                if ($locked->is_protected) {
                    throw new ApiException(__('messages.access_control.protected_role_immutable'), 422);
                }
            }

            $locked->display_name = array_key_exists('display_name', $data)
                ? trim((string) $data['display_name'])
                : $locked->display_name;
            $locked->description = array_key_exists('description', $data)
                ? $data['description']
                : $locked->description;
            $locked->version = $locked->version + 1;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'access_role.updated',
                'role',
                $locked->id,
                $old,
                [
                    'display_name' => $locked->display_name,
                    'description' => $locked->description,
                    'version' => $locked->version,
                ]
            );

            return $this->presentRole($locked->fresh(['permissions']), detailed: true);
        });
    }

    /**
     * @param  array{permission_ids: list<int>, version: int, reason: string, password_confirmation?: string|null}  $data
     * @return array<string, mixed>
     */
    public function syncRolePermissions(User $actor, Role $role, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);

        if ($role->is_protected || in_array($role->name, ['super_admin', 'admin'], true)) {
            throw new ApiException(__('messages.access_control.protected_role_permissions_immutable'), 422);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException(__('messages.access_control.reason_required'), 422);
        }

        $permissions = $this->resolveAssignablePermissions(array_values(array_unique(array_map('intval', $data['permission_ids'] ?? []))));

        return DB::transaction(function () use ($actor, $role, $data, $permissions, $reason) {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $data['version']);

            $locked->load('permissions');
            $oldNames = $locked->permissions->pluck('name')->sort()->values()->all();
            $newNames = $permissions->pluck('name')->sort()->values()->all();
            $added = array_values(array_diff($newNames, $oldNames));
            $removed = array_values(array_diff($oldNames, $newNames));

            $classification = PermissionRegistry::classifyPermissionNames(array_merge($added, $removed));
            if ($classification['has_critical']) {
                $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
            }

            $locked->permissions()->sync($permissions->pluck('id')->all());
            $locked->version = $locked->version + 1;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'access_role.permissions_updated',
                'role',
                $locked->id,
                [
                    'permission_names' => $oldNames,
                    'version' => (int) $data['version'],
                ],
                [
                    'permission_names' => $newNames,
                    'added' => $added,
                    'removed' => $removed,
                    'reason' => $reason,
                    'has_critical' => $classification['has_critical'],
                    'has_sensitive' => $classification['has_sensitive'],
                    'version' => $locked->version,
                ]
            );

            return [
                'role' => $this->presentRole($locked->fresh(['permissions']), detailed: true),
                'added' => $added,
                'removed' => $removed,
            ];
        });
    }

    /**
     * @param  array{reason: string}  $data
     * @return array<string, mixed>
     */
    public function archiveRole(User $actor, Role $role, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);

        if ($role->is_system || $role->is_protected) {
            throw new ApiException(__('messages.access_control.cannot_archive_system_role'), 422);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException(__('messages.access_control.reason_required'), 422);
        }

        return DB::transaction(function () use ($actor, $role, $reason) {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            $activeAssignments = User::query()
                ->where('role_id', $locked->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count();

            if ($activeAssignments > 0) {
                throw new ApiException(__('messages.access_control.cannot_archive_role_with_employees'), 422);
            }

            $locked->is_archived = true;
            $locked->is_assignable = false;
            $locked->archived_at = now();
            $locked->version = $locked->version + 1;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'access_role.archived',
                'role',
                $locked->id,
                ['is_archived' => false],
                ['is_archived' => true, 'reason' => $reason, 'version' => $locked->version]
            );

            return $this->presentRole($locked->fresh(['permissions']), detailed: true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreRole(User $actor, Role $role): array
    {
        $this->assertActorIsSuperAdmin($actor);

        return DB::transaction(function () use ($actor, $role) {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_archived) {
                throw new ApiException(__('messages.access_control.role_not_archived'), 422);
            }

            $locked->is_archived = false;
            $locked->is_assignable = true;
            $locked->archived_at = null;
            $locked->version = $locked->version + 1;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'access_role.restored',
                'role',
                $locked->id,
                ['is_archived' => true],
                ['is_archived' => false, 'version' => $locked->version]
            );

            return $this->presentRole($locked->fresh(['permissions']), detailed: true);
        });
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function roleEmployees(Role $role, int $perPage = 20): LengthAwarePaginator
    {
        return User::query()
            ->where('role_id', $role->id)
            ->whereIn('user_type', [UserType::Admin, UserType::Employee])
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function roleAuditLogs(Role $role, int $perPage = 20): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'role')
            ->where('entity_id', $role->id)
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeAccess(User $employee): array
    {
        $this->assertDashboardEmployee($employee);
        $employee->load(['role.permissions', 'directPermissions']);

        return $this->presentEmployeeAccess($employee);
    }

    /**
     * Single primary role model: assigns exactly one role_id.
     *
     * @param  array{role_id: int, reason: string, password_confirmation?: string|null}  $data
     * @return array<string, mixed>
     */
    public function syncEmployeeRole(User $actor, User $employee, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);
        $this->assertDashboardEmployee($employee);

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException(__('messages.access_control.reason_required'), 422);
        }

        $role = Role::query()->whereKey((int) $data['role_id'])->first();
        if ($role === null || $role->name === 'citizen') {
            throw new ApiException(__('messages.access_control.invalid_role_assignment'), 422);
        }
        if (! $role->canBeAssigned() && ! ($role->is_protected && in_array($role->name, ['super_admin', 'admin'], true))) {
            throw new ApiException(__('messages.access_control.role_not_assignable'), 422);
        }
        if ($role->is_archived) {
            throw new ApiException(__('messages.access_control.archived_role_cannot_be_assigned'), 422);
        }

        if ($role->is_protected || in_array($role->name, ['super_admin', 'admin'], true)) {
            $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
        }

        return DB::transaction(function () use ($actor, $employee, $role, $reason) {
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked->load('role');

            $oldRoleName = $locked->role?->name;
            $oldRoleId = $locked->role_id;

            if ($oldRoleName === 'super_admin' && $role->name !== 'super_admin') {
                $this->assertNotLastActiveSuperAdmin($locked);
            }

            $locked->role_id = $role->id;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'employee.roles_updated',
                'user',
                $locked->id,
                ['role_id' => $oldRoleId, 'role_name' => $oldRoleName],
                [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'added' => [$role->name],
                    'removed' => $oldRoleName && $oldRoleName !== $role->name ? [$oldRoleName] : [],
                    'reason' => $reason,
                    'model' => 'single_primary_role',
                ]
            );

            return $this->presentEmployeeAccess($locked->fresh(['role.permissions', 'directPermissions']));
        });
    }

    /**
     * @param  array{permission_ids: list<int>, reason: string, password_confirmation?: string|null}  $data
     * @return array<string, mixed>
     */
    public function syncDirectPermissions(User $actor, User $employee, array $data): array
    {
        $this->assertActorIsSuperAdmin($actor);
        $this->assertDashboardEmployee($employee);

        if ($employee->isSuperAdmin()) {
            throw new ApiException(__('messages.access_control.cannot_assign_direct_permissions_to_super_admin'), 422);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException(__('messages.access_control.reason_required'), 422);
        }

        $permissions = $this->resolveAssignablePermissions(array_values(array_unique(array_map('intval', $data['permission_ids'] ?? []))));

        return DB::transaction(function () use ($actor, $employee, $permissions, $data, $reason) {
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked->load('directPermissions');

            $oldNames = $locked->directPermissions->pluck('name')->sort()->values()->all();
            $newNames = $permissions->pluck('name')->sort()->values()->all();
            $added = array_values(array_diff($newNames, $oldNames));
            $removed = array_values(array_diff($oldNames, $newNames));

            $classification = PermissionRegistry::classifyPermissionNames(array_merge($added, $removed));
            if ($classification['has_critical']) {
                $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
            }

            $locked->directPermissions()->sync($permissions->pluck('id')->all());

            $this->auditLogs->log(
                $actor,
                'employee.direct_permissions_updated',
                'user',
                $locked->id,
                ['direct_permissions' => $oldNames],
                [
                    'direct_permissions' => $newNames,
                    'added' => $added,
                    'removed' => $removed,
                    'reason' => $reason,
                    'note' => 'Removing a direct permission does not remove the same permission inherited from the role.',
                ]
            );

            return $this->presentEmployeeAccess($locked->fresh(['role.permissions', 'directPermissions']));
        });
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function employeeAccessAuditLogs(User $employee, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertDashboardEmployee($employee);

        return AuditLog::query()
            ->where('entity_type', 'user')
            ->where('entity_id', $employee->id)
            ->whereIn('action', [
                'employee.roles_updated',
                'employee.direct_permissions_updated',
                'employee.role_assigned',
                'employee.created',
                'employee.updated',
                'employee.activated',
                'employee.deactivated',
            ])
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRole(Role $role, bool $detailed = false): array
    {
        $role->loadMissing('permissions');
        $permissionNames = $role->permissions->pluck('name')->sort()->values()->all();
        $classification = PermissionRegistry::classifyPermissionNames($permissionNames);

        $payload = [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->display_name ?: $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'is_protected' => (bool) $role->is_protected,
            'is_assignable' => (bool) $role->is_assignable,
            'is_archived' => (bool) $role->is_archived,
            'permissions_count' => (int) ($role->permissions_count ?? $role->permissions->count()),
            'employees_count' => (int) ($role->users_count ?? $role->users()->count()),
            'sensitive_permissions_count' => count($classification['sensitive']),
            'critical_permissions_count' => count($classification['critical']),
            'created_at' => optional($role->created_at)?->toIso8601String(),
            'updated_at' => optional($role->updated_at)?->toIso8601String(),
            'version' => (int) $role->version,
            'actions' => [
                'can_view' => true,
                'can_update' => ! $role->is_protected && ! $role->is_archived,
                'can_sync_permissions' => ! $role->is_protected && ! in_array($role->name, ['super_admin', 'admin'], true),
                'can_archive' => ! $role->is_system && ! $role->is_protected && ! $role->is_archived,
                'can_restore' => (bool) $role->is_archived && ! $role->is_protected,
                'can_view_employees' => true,
                'can_view_audit_logs' => true,
            ],
        ];

        if ($detailed) {
            $grouped = [];
            foreach ($permissionNames as $name) {
                $meta = PermissionRegistry::find($name);
                $module = (string) ($meta['module'] ?? 'unknown');
                $grouped[$module] ??= [
                    'module' => $module,
                    'module_label' => PermissionRegistry::moduleLabel($module),
                    'permissions' => [],
                ];
                $grouped[$module]['permissions'][] = [
                    'name' => $name,
                    'label' => $meta['label'] ?? $name,
                    'risk_level' => $meta['risk_level'] ?? 'normal',
                    'risk_label' => PermissionRegistry::riskLabel((string) ($meta['risk_level'] ?? 'normal')),
                ];
            }
            ksort($grouped);

            $payload['permissions_grouped'] = array_values($grouped);
            $payload['permission_names'] = $permissionNames;
            $payload['risk_summary'] = $classification;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentEmployeeAccess(User $employee): array
    {
        $roleNames = $employee->rolePermissionNames();
        $directNames = $employee->directPermissionNames();
        $effective = $employee->isSuperAdmin() ? ['*'] : $employee->effectivePermissionNames();
        $withSource = $employee->isSuperAdmin()
            ? [['name' => '*', 'source' => 'super_admin_bypass']]
            : $employee->effectivePermissionsWithSource();

        $enriched = [];
        foreach ($withSource as $item) {
            if ($item['name'] === '*') {
                $enriched[] = [
                    'name' => '*',
                    'label' => 'جميع الصلاحيات',
                    'source' => $item['source'],
                    'module' => 'dashboard',
                    'module_label' => PermissionRegistry::moduleLabel('dashboard'),
                ];
                continue;
            }
            $meta = PermissionRegistry::find($item['name']);
            $enriched[] = [
                'name' => $item['name'],
                'label' => $meta['label'] ?? $item['name'],
                'source' => $item['source'],
                'module' => $meta['module'] ?? 'unknown',
                'module_label' => PermissionRegistry::moduleLabel((string) ($meta['module'] ?? 'unknown')),
                'risk_level' => $meta['risk_level'] ?? 'normal',
            ];
        }

        $classification = PermissionRegistry::classifyPermissionNames(
            array_values(array_filter($effective, static fn ($n) => $n !== '*'))
        );

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'user_type' => $employee->user_type?->value,
                'is_active' => (bool) $employee->is_active,
                'is_super_admin' => $employee->isSuperAdmin(),
            ],
            'role' => $employee->role ? [
                'id' => $employee->role->id,
                'name' => $employee->role->name,
                'label' => $employee->role->display_name ?: $employee->role->name,
            ] : null,
            'role_permissions' => $roleNames,
            'direct_permissions' => $directNames,
            'effective_permissions' => $effective,
            'effective_permissions_detailed' => $enriched,
            'risk_summary' => $classification,
            'model' => 'single_primary_role_plus_direct_grants',
            'actions' => [
                'can_update_role' => ! $employee->isSuperAdmin() || true,
                'can_update_direct_permissions' => ! $employee->isSuperAdmin(),
                'can_view_audit_logs' => true,
            ],
        ];
    }

    /**
     * @param  list<int>  $permissionIds
     * @return \Illuminate\Support\Collection<int, Permission>
     */
    private function resolveAssignablePermissions(array $permissionIds)
    {
        if ($permissionIds === []) {
            return collect();
        }

        $permissions = Permission::query()->whereIn('id', $permissionIds)->get();
        if ($permissions->count() !== count($permissionIds)) {
            throw new ApiException(__('messages.access_control.invalid_permission'), 422);
        }

        foreach ($permissions as $permission) {
            if (! PermissionRegistry::isKnown($permission->name)) {
                throw new ApiException(__('messages.access_control.unregistered_permission'), 422);
            }
            if (! PermissionRegistry::isAssignable($permission->name)) {
                throw new ApiException(__('messages.access_control.permission_not_assignable'), 422);
            }
            if (PermissionRegistry::isProtected($permission->name)) {
                throw new ApiException(__('messages.access_control.protected_permission'), 422);
            }
        }

        return $permissions;
    }

    private function assertActorIsSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new ApiException(__('messages.access_control.super_admin_required'), 403);
        }
    }

    private function assertRoleEditable(Role $role): void
    {
        if ($role->is_archived) {
            throw new ApiException(__('messages.access_control.cannot_update_archived_role'), 422);
        }
    }

    private function assertVersion(Role $role, int $version): void
    {
        if ((int) $role->version !== $version) {
            throw new ApiException(__('messages.access_control.stale_version'), 409);
        }
    }

    private function assertPasswordConfirmation(User $actor, mixed $password): void
    {
        if (! is_string($password) || $password === '' || ! Hash::check($password, $actor->password)) {
            throw new ApiException(__('messages.access_control.password_confirmation_failed'), 422);
        }
    }

    private function assertDashboardEmployee(User $user): void
    {
        if ($user->isCitizen() || ! $user->isDashboardUser()) {
            throw new ApiException(__('messages.access_control.not_dashboard_employee'), 404);
        }
    }

    private function assertNotLastActiveSuperAdmin(User $user): void
    {
        if ($user->role?->name !== 'super_admin' || ! $user->is_active) {
            return;
        }

        $count = User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $q) => $q->where('name', 'super_admin'))
            ->count();

        if ($count <= 1) {
            throw new ApiException(__('messages.access_control.cannot_remove_last_super_admin'), 422);
        }
    }
}
