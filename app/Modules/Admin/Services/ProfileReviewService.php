<?php

namespace App\Modules\Admin\Services;

use App\Enums\ProfileStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProfileReviewService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'citizen'))
            ->where('profile_status', $status)
            ->orderByDesc('profile_submitted_at')
            ->orderByDesc('id');

        if ($search !== null && $search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('national_id', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    public function getCitizenForReview(int $userId): User
    {
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            throw new ApiException('messages.http.not_found', 404);
        }

        $this->assertReviewableCitizen($user);

        return $user;
    }

    public function approve(User $reviewer, int $userId): User
    {
        $citizen = $this->getCitizenForReview($userId);

        if ($citizen->profileStatus() === ProfileStatus::Approved) {
            throw new ApiException('messages.profile.already_approved', 422);
        }

        if ($citizen->profileStatus() !== ProfileStatus::PendingReview) {
            throw new ApiException('messages.profile.not_pending_review', 422);
        }

        if (! $citizen->hasCompletedProfile()) {
            throw new ApiException('messages.profile.must_complete', 422);
        }

        return DB::transaction(function () use ($reviewer, $citizen) {
            $oldStatus = $citizen->profile_status;

            $citizen->profile_status = ProfileStatus::Approved;
            $citizen->profile_rejection_reason = null;
            $citizen->profile_reviewed_by = $reviewer->id;
            $citizen->profile_reviewed_at = now();
            $citizen->save();

            $this->auditLogs->log(
                $reviewer,
                'profile_approved',
                'user',
                $citizen->id,
                ['profile_status' => $oldStatus instanceof ProfileStatus ? $oldStatus->value : (string) $oldStatus],
                ['profile_status' => ProfileStatus::Approved->value]
            );

            $this->notifications->sendLocalizedToUser(
                $citizen->id,
                'messages.notifications.profile_approved_title',
                'messages.notifications.profile_approved_body',
                [],
                'profile.approved',
                ['profile_status' => ProfileStatus::Approved->value]
            );

            return $citizen->fresh();
        });
    }

    public function reject(User $reviewer, int $userId, string $rejectionReason): User
    {
        $citizen = $this->getCitizenForReview($userId);

        if ($citizen->profileStatus() !== ProfileStatus::PendingReview) {
            throw new ApiException('messages.profile.not_pending_review', 422);
        }

        return DB::transaction(function () use ($reviewer, $citizen, $rejectionReason) {
            $oldStatus = $citizen->profile_status;

            $citizen->profile_status = ProfileStatus::Rejected;
            $citizen->profile_rejection_reason = $rejectionReason;
            $citizen->profile_reviewed_by = $reviewer->id;
            $citizen->profile_reviewed_at = now();
            $citizen->save();

            $this->auditLogs->log(
                $reviewer,
                'profile_rejected',
                'user',
                $citizen->id,
                ['profile_status' => $oldStatus instanceof ProfileStatus ? $oldStatus->value : (string) $oldStatus],
                [
                    'profile_status' => ProfileStatus::Rejected->value,
                    'profile_rejection_reason' => $rejectionReason,
                ]
            );

            $this->notifications->sendLocalizedToUser(
                $citizen->id,
                'messages.notifications.profile_rejected_title',
                'messages.notifications.profile_rejected_body',
                [],
                'profile.rejected',
                [
                    'profile_status' => ProfileStatus::Rejected->value,
                    'rejection_reason' => $rejectionReason,
                ]
            );

            return $citizen->fresh();
        });
    }

    private function assertReviewableCitizen(User $user): void
    {
        if (! $user->isCitizen()) {
            throw new ApiException('messages.profile.not_citizen', 422);
        }
    }
}
