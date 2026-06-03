<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DashboardEmployeeService
{
    public function __construct(
        private readonly AuthRepository $users,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->where('user_type', UserType::Employee)
            ->with('role')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function getEmployee(int $userId): User
    {
        $user = User::query()->whereKey($userId)->with('role')->first();

        if ($user === null || $user->user_type !== UserType::Employee) {
            throw new ApiException('messages.dashboard.employee_not_found', 404);
        }

        return $user;
    }

    public function create(array $data): User
    {
        $role = $this->resolveEmployeeRole($data['role']);

        return User::query()->create([
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
    }

    public function update(User $user, array $data): User
    {
        $this->assertEmployee($user);

        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        ], static fn ($v) => $v !== null);

        if (! empty($data['role'])) {
            $payload['role_id'] = $this->resolveEmployeeRole($data['role'])->id;
        }

        return $this->users->updateUser($user, $payload);
    }

    public function toggleActive(User $user): User
    {
        $this->assertEmployee($user);

        return $this->users->updateUser($user, [
            'is_active' => ! $user->is_active,
        ]);
    }

    /**
     * @return array{password: string}
     */
    public function resetPassword(User $user, ?string $password = null): array
    {
        $this->assertEmployee($user);

        $plain = $password ?? Str::password(12);

        $this->users->updateUser($user, ['password' => $plain]);
        $user->tokens()->delete();

        return ['password' => $plain];
    }

    public function assignRole(User $user, string $roleName): User
    {
        $this->assertEmployee($user);

        $role = $this->resolveEmployeeRole($roleName);

        return $this->users->updateUser($user, ['role_id' => $role->id]);
    }

    private function assertEmployee(User $user): void
    {
        if ($user->user_type !== UserType::Employee) {
            throw new ApiException('messages.dashboard.employee_not_found', 404);
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
