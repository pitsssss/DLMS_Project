<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardEmployeeService
{
    private const ALLOWED_SORT_COLUMNS = ['name', 'email', 'created_at'];

    public function __construct(
        private readonly AuthRepository $users,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     is_active?: bool|null,
     *     user_type?: string|null,
     *     sort_by?: string,
     *     sort_direction?: string,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $sortBy = 'created_at';
        }

        return $this->baseQuery($filters)
            ->with('role')
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Global employee-management statistics (not affected by list filters/search).
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function statistics(): array
    {
        $base = $this->scopedUsersQuery();

        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
        ];
    }

    /**
     * Role options for the employee filter (excludes citizen).
     *
     * @return list<array{id: int, name: string, display_name: string|null}>
     */
    public function roleOptions(): array
    {
        return Role::query()
            ->where('name', '!=', 'citizen')
            ->orderBy('display_name')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name'])
            ->map(static fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])
            ->values()
            ->all();
    }

    public function getEmployee(int $userId): User
    {
        $user = User::query()->whereKey($userId)->with('role')->first();

        if ($user === null || ! $this->isManageableDashboardUser($user)) {
            throw new ApiException('messages.dashboard.employee_not_found', 404);
        }

        return $user;
    }

    public function create(array $data): User
    {
        $role = $this->resolveEmployeeRole($data['role']);

        $employee = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $role->id,
            'user_type' => UserType::Employee,
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Approved,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->auditLogs->log(
            request()->user(),
            'employee.created',
            'user',
            $employee->id,
            null,
            [
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $role->name,
                'is_active' => true,
            ],
            request()
        );

        return $employee;
    }

    public function update(User $user, array $data): User
    {
        $this->assertManageableDashboardUser($user);

        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        ], static fn ($v) => $v !== null);

        if (! empty($data['role'])) {
            $payload['role_id'] = $this->resolveEmployeeRole($data['role'])->id;
        }

        if (array_key_exists('is_active', $payload) && $payload['is_active'] === false) {
            $this->assertCanDeactivate($user, request()->user());
        }

        $old = [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => (bool) $user->is_active,
        ];

        $updated = $this->users->updateUser($user, $payload);

        $this->auditLogs->log(
            request()->user(),
            'employee.updated',
            'user',
            $updated->id,
            $old,
            [
                'name' => $updated->name,
                'email' => $updated->email,
                'role_id' => $updated->role_id,
                'is_active' => (bool) $updated->is_active,
            ],
            request()
        );

        return $updated;
    }

    /**
     * Set or toggle employee active status.
     *
     * @param  bool|null  $desired  When null, flips current status (legacy toggle).
     */
    public function setActive(User $user, ?bool $desired, User $actor): User
    {
        $this->assertManageableDashboardUser($user);

        $target = $desired ?? ! (bool) $user->is_active;
        $currentlyActive = (bool) $user->is_active;

        if ($currentlyActive === $target) {
            return $user->loadMissing('role');
        }

        if ($target === false) {
            $this->assertCanDeactivate($user, $actor);
        }

        return DB::transaction(function () use ($user, $target, $actor, $currentlyActive) {
            $updated = $this->users->updateUser($user, [
                'is_active' => $target,
            ]);

            $this->auditLogs->log(
                $actor,
                $target ? 'employee.activated' : 'employee.deactivated',
                'user',
                $updated->id,
                ['is_active' => $currentlyActive],
                ['is_active' => $target],
                request()
            );

            return $updated->loadMissing('role');
        });
    }

    /**
     * @deprecated Prefer setActive(); kept for callers that still flip blindly.
     */
    public function toggleActive(User $user): User
    {
        return $this->setActive($user, null, request()->user());
    }

    /**
     * @return array{password: string}
     */
    public function resetPassword(User $user, ?string $password = null): array
    {
        $this->assertManageableDashboardUser($user);

        $plain = $password ?? Str::password(12);

        $this->users->updateUser($user, ['password' => $plain]);
        $user->tokens()->delete();

        $this->auditLogs->log(
            request()->user(),
            'employee.password_reset',
            'user',
            $user->id,
            null,
            ['password_reset' => true],
            request()
        );

        return ['password' => $plain];
    }

    public function assignRole(User $user, string $roleName): User
    {
        $this->assertManageableDashboardUser($user);

        $role = $this->resolveEmployeeRole($roleName);
        $oldRole = $user->role?->name;

        $updated = $this->users->updateUser($user, ['role_id' => $role->id]);

        $this->auditLogs->log(
            request()->user(),
            'employee.role_assigned',
            'user',
            $updated->id,
            ['role' => $oldRole],
            ['role' => $role->name],
            request()
        );

        return $updated;
    }

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     is_active?: bool|null,
     *     user_type?: string|null
     * }  $filters
     * @return Builder<User>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = $this->scopedUsersQuery();

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $like = '%'.$search.'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if (! empty($filters['role_id'])) {
            $query->where('role_id', (int) $filters['role_id']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['user_type'])) {
            $query->where('user_type', $filters['user_type']);
        }

        return $query;
    }

    /**
     * @return Builder<User>
     */
    private function scopedUsersQuery(): Builder
    {
        return User::query()->whereIn('user_type', [
            UserType::Employee,
            UserType::Admin,
        ]);
    }

    private function isManageableDashboardUser(User $user): bool
    {
        return in_array($user->user_type, [UserType::Employee, UserType::Admin], true);
    }

    private function assertManageableDashboardUser(User $user): void
    {
        if (! $this->isManageableDashboardUser($user)) {
            throw new ApiException('messages.dashboard.employee_not_found', 404);
        }
    }

    private function assertCanDeactivate(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw new ApiException('messages.dashboard.cannot_deactivate_self', 422);
        }

        if ($user->role?->name === 'super_admin' && (bool) $user->is_active) {
            $otherActiveSuperAdmins = User::query()
                ->whereIn('user_type', [UserType::Employee, UserType::Admin])
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->whereHas('role', fn (Builder $q) => $q->where('name', 'super_admin'))
                ->exists();

            if (! $otherActiveSuperAdmins) {
                throw new ApiException('messages.dashboard.cannot_deactivate_last_super_admin', 422);
            }
        }
    }

    private function resolveEmployeeRole(string $roleName): Role
    {
        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null || $role->name === 'citizen') {
            throw new ApiException('messages.dashboard.invalid_role', 422);
        }

        return $role;
    }
}
