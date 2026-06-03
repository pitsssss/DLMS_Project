<?php

namespace App\Http\Middleware;

use App\Enums\ProfileStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCitizen()) {
            return $this->deny(__('messages.middleware.citizen_only'));
        }

        if (! $user->profile_completed) {
            return $this->deny(__('messages.profile.must_complete'));
        }

        $status = $user->profile_status instanceof ProfileStatus
            ? $user->profile_status
            : ProfileStatus::tryFrom((string) $user->profile_status);

        if ($status === ProfileStatus::PendingReview) {
            return $this->deny(__('messages.profile.pending_review'));
        }

        if ($status === ProfileStatus::Rejected) {
            return $this->deny(__('messages.profile.rejected_blocked'));
        }

        if ($status !== ProfileStatus::Approved) {
            return $this->deny(__('messages.profile.must_complete'));
        }

        if (! $user->is_active) {
            return $this->deny(__('messages.auth.account_inactive'));
        }

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) [],
        ], 403);
    }
}
