<?php

namespace App\Modules\Auth\Services;

use App\Enums\ProfileStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;

class ProfileService
{
    private const SENSITIVE_PROFILE_FIELDS = [
        'name',
        'national_id',
        'birth_date',
        'governorate',
        'address',
    ];

    public function __construct(
        protected AuthRepository $users,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(User $user): array
    {
        if (! $user->isCitizen()) {
            throw new ApiException('messages.auth.citizen_profile_only', 403);
        }

        return [
            'profile_completed' => (bool) $user->profile_completed,
            'profile_status' => $this->resolveStatusValue($user),
            'profile_rejection_reason' => $user->profile_rejection_reason,
            'profile_submitted_at' => $user->profile_submitted_at?->toIso8601String(),
            'profile_reviewed_at' => $user->profile_reviewed_at?->toIso8601String(),
        ];
    }

    public function completeProfile(User $user, array $data): User
    {
        if (! $user->isCitizen()) {
            throw new ApiException('messages.auth.citizen_profile_only', 403);
        }

        $data['profile_completed'] = true;

        return $this->submitForReview($user, $data);
    }

    /**
     * @return array{user: User, submitted_for_review: bool}
     */
    public function updateProfile(User $user, array $data): array
    {
        if (! $user->isCitizen()) {
            throw new ApiException('messages.auth.citizen_profile_only', 403);
        }

        $payload = array_filter(
            $data,
            static fn ($value) => $value !== null && $value !== ''
        );

        if ($payload === []) {
            return ['user' => $user, 'submitted_for_review' => false];
        }

        $status = $this->resolveStatus($user);
        $submittedForReview = $status === ProfileStatus::Rejected
            || ($status === ProfileStatus::Approved && $this->touchesSensitiveFields($payload))
            || $status === ProfileStatus::PendingReview;

        if ($submittedForReview) {
            $payload = array_merge($payload, $this->pendingReviewAttributes());
        }

        return [
            'user' => $this->users->updateUser($user, $payload),
            'submitted_for_review' => $submittedForReview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profileApprovalErrorForStatus(User $user): ?array
    {
        if (! $user->isCitizen()) {
            return null;
        }

        $status = $this->resolveStatus($user);

        return match ($status) {
            ProfileStatus::Incomplete => [
                'key' => 'messages.profile.must_complete',
                'conversation' => 'messages.profile.ai_must_complete',
            ],
            ProfileStatus::PendingReview => [
                'key' => 'messages.profile.pending_review',
                'conversation' => 'messages.profile.ai_pending_review',
            ],
            ProfileStatus::Rejected => [
                'key' => 'messages.profile.rejected_blocked',
                'conversation' => 'messages.profile.ai_rejected',
            ],
            ProfileStatus::Approved => null,
        };
    }

    public function assertCanUseCitizenServices(User $user): void
    {
        if (! $user->canUseCitizenServices()) {
            $error = $this->profileApprovalErrorForStatus($user);

            throw new ApiException($error['key'] ?? 'messages.profile.must_complete', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submitForReview(User $user, array $data): User
    {
        return $this->users->updateUser($user, array_merge($data, $this->pendingReviewAttributes()));
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingReviewAttributes(): array
    {
        return [
            'profile_status' => ProfileStatus::PendingReview->value,
            'profile_submitted_at' => now(),
            'profile_rejection_reason' => null,
            'profile_reviewed_by' => null,
            'profile_reviewed_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function touchesSensitiveFields(array $payload): bool
    {
        foreach (self::SENSITIVE_PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function resolveStatus(User $user): ProfileStatus
    {
        if ($user->profile_status instanceof ProfileStatus) {
            return $user->profile_status;
        }

        return ProfileStatus::tryFrom((string) $user->profile_status) ?? ProfileStatus::Incomplete;
    }

    private function resolveStatusValue(User $user): string
    {
        return $this->resolveStatus($user)->value;
    }
}
