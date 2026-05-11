<?php

namespace App\Modules\Auth\Services;

use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use App\Modules\Auth\Repositories\PasswordResetTokenRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        protected AuthRepository $users,
        protected OtpService $otps,
        protected PasswordResetTokenRepository $passwordResetTokens
    ) {}

    public function register(array $data): array
    {
        if ($this->users->findByEmail($data['email'])) {
            throw new ApiException('This email is already registered.', 422);
        }

        return DB::transaction(function () use ($data) {
            $this->users->createCitizen([
                'name' => $data['name'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'user_type' => UserType::Citizen,
                'is_active' => false,
                'email_verified_at' => null,
                'phone_verified_at' => null,
            ]);

            $this->otps->sendEmailOtp($data['email'], OtpPurpose::Register);

            return [
                'expires_in_minutes' => $this->otps->expiryMinutes(),
            ];
        });
    }

    public function verifyOtp(array $data): array
    {
        $purpose = OtpPurpose::from($data['purpose']);

        if ($purpose !== OtpPurpose::Register) {
            throw new ApiException('Invalid verification purpose for this endpoint.', 422);
        }

        $this->otps->verifyEmailOtp($data['email'], $data['code'], $purpose);

        $user = $this->users->findByEmail($data['email']);
        if (! $user) {
            throw new ApiException('User not found.', 404);
        }

        if (! $user->isCitizen()) {
            throw new ApiException('Invalid account type for this verification.', 403);
        }

        $user = $this->users->updateUser($user, [
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user' => $user->load('role'),
            'token' => $token,
        ];
    }

    public function verifyForgotPasswordOtp(string $email, string $code): array
    {
        return DB::transaction(function () use ($email, $code) {
            $this->otps->verifyEmailOtp($email, $code, OtpPurpose::ForgotPassword);

            $user = $this->users->findByEmail($email);
            if (! $user) {
                throw new ApiException('User not found.', 404);
            }

            $this->passwordResetTokens->invalidateAllPendingForEmail($email);

            $created = $this->passwordResetTokens->createToken($email);

            return [
                'reset_token' => $created['plain_token'],
            ];
        });
    }

    public function resetPassword(string $email, string $plainResetToken, string $newPassword): void
    {
        DB::transaction(function () use ($email, $plainResetToken, $newPassword) {
            $user = $this->users->findByEmail($email);
            if (! $user) {
                throw new ApiException('User not found.', 404);
            }

            $row = $this->passwordResetTokens->findMatchingValidToken($email, $plainResetToken);
            if (! $row) {
                throw new ApiException('Invalid or expired reset token.', 422);
            }

            $this->passwordResetTokens->markUsed($row);

            $this->users->updateUser($user, [
                'password' => $newPassword,
            ]);

            $user->tokens()->delete();
        });
    }

    public function login(array $data): array
    {
        $user = $this->users->findByLoginIdentifier(
            $data['email'] ?? null,
            $data['identifier'] ?? null
        );

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('Invalid credentials.', 401);
        }

        if (! $user->is_active) {
            throw new ApiException('This account is inactive.', 403);
        }

        if ($user->isCitizen()) {
            if (! $user->email_verified_at) {
                throw new ApiException('Please verify your email before logging in.', 403);
            }
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user' => $user->load('role'),
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }
    }

    public function completeProfile(User $user, array $data): User
    {
        if (! $user->isCitizen()) {
            throw new ApiException('Only citizens can complete this profile.', 403);
        }

        $data['profile_completed'] = true;

        return $this->users->updateUser($user, $data);
    }

    public function updateProfile(User $user, array $data): User
    {
        $payload = array_filter(
            $data,
            static fn ($v) => $v !== null && $v !== ''
        );

        return $this->users->updateUser($user, $payload);
    }

    public function changePassword(User $user, array $data): void
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException('Current password is incorrect.', 422);
        }

        $this->users->updateUser($user, [
            'password' => $data['password'],
        ]);
    }

    public function forgotPassword(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user) {
            $this->otps->sendEmailOtp($email, OtpPurpose::ForgotPassword);
        }
    }
}
