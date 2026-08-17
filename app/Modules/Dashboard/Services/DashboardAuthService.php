<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use App\Modules\Auth\Repositories\PasswordResetTokenRepository;
use App\Modules\Auth\Services\OtpService;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DashboardAuthService
{
    public function __construct(
        private readonly AuthRepository $users,
        private readonly OtpService $otps,
        private readonly PasswordResetTokenRepository $passwordResetTokens,
        private readonly DashboardModuleService $modules,
        private readonly EmployeeSessionService $employeeSessions,
    ) {}

    /**
     * @return array{token: string, user: User}
     */
    public function login(string $email, string $password, ?Request $request = null): array
    {
        $user = $this->users->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ApiException('messages.dashboard.invalid_credentials', 401);
        }

        if ($user->isCitizen() || ! $user->isDashboardUser()) {
            throw new ApiException('messages.dashboard.citizen_not_allowed', 403);
        }

        if (! $user->is_active) {
            throw new ApiException('messages.dashboard.inactive_account', 403);
        }

        $user->load(['role.permissions', 'directPermissions']);

        $request ??= request();
        $started = $this->employeeSessions->startDashboardSession($user, $request);

        return [
            'token' => $started['token'],
            'user' => $started['user'],
        ];
    }

    public function logout(User $user, ?Request $request = null): void
    {
        $this->employeeSessions->markExplicitLogout($user, $request ?? request());
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $user): array
    {
        $user->load(['role.permissions', 'directPermissions']);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type?->value,
                'is_active' => (bool) $user->is_active,
                'is_super_admin' => $user->isSuperAdmin(),
                'is_root_super_admin' => $user->isRootSuperAdmin(),
                'can_manage_employee_sessions' => $user->canManageEmployeeSessions(),
                'role' => [
                    'name' => $user->role?->name,
                    'display_name' => $user->role?->display_name,
                ],
                'permissions' => $user->permissionNames(),
                'role_permissions' => $user->isSuperAdmin() ? ['*'] : $user->rolePermissionNames(),
                'direct_permissions' => $user->isSuperAdmin() ? [] : $user->directPermissionNames(),
                'effective_permissions' => $user->permissionNames(),
                'dashboard_modules' => $this->modules->modulesForUser($user),
            ],
        ];
    }

    public function forgotPassword(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user && $user->isDashboardUser() && ! $user->isCitizen()) {
            $this->otps->sendEmailOtp($email, OtpPurpose::DashboardForgotPassword);
        }
    }

    /**
     * @return array{reset_token: string}
     */
    public function verifyForgotPasswordOtp(string $email, string $code): array
    {
        $this->otps->verifyEmailOtp($email, $code, OtpPurpose::DashboardForgotPassword);

        return DB::transaction(function () use ($email) {
            $user = $this->users->findByEmail($email);
            if ($user === null || ! $user->isDashboardUser() || $user->isCitizen()) {
                throw new ApiException('messages.dashboard.invalid_credentials', 422);
            }

            $this->passwordResetTokens->invalidateAllPendingForEmail($email);
            $created = $this->passwordResetTokens->createToken($email);

            return ['reset_token' => $created['plain_token']];
        });
    }

    public function resetPassword(string $email, string $plainResetToken, string $newPassword): void
    {
        DB::transaction(function () use ($email, $plainResetToken, $newPassword) {
            $user = $this->users->findByEmail($email);
            if ($user === null || ! $user->isDashboardUser() || $user->isCitizen()) {
                throw new ApiException('messages.auth.invalid_reset_token', 422);
            }

            $row = $this->passwordResetTokens->findMatchingValidToken($email, $plainResetToken);
            if ($row === null) {
                throw new ApiException('messages.auth.invalid_reset_token', 422);
            }

            $this->passwordResetTokens->markUsed($row);
            $this->users->updateUser($user, ['password' => $newPassword]);
            $user->tokens()->delete();
        });
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new ApiException('messages.auth.current_password_wrong', 422);
        }

        $this->users->updateUser($user, ['password' => $newPassword]);
    }
}
