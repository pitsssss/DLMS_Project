<?php

namespace App\Modules\Licenses\Services;

use App\Enums\LicenseStatus;
use App\Exceptions\ApiException;
use App\Models\License;
use App\Support\BusinessClock;
use App\Support\Msg;

final class LicenseTransitionPolicy
{
    public function assertCanBlock(License $license): void
    {
        $status = $license->effectiveStatus();

        if ($status === LicenseStatus::Blocked) {
            return;
        }

        if (! in_array($status, [LicenseStatus::Active], true)) {
            throw new ApiException('messages.licenses.cannot_block_status', 422);
        }
    }

    public function assertCanUnblock(License $license): void
    {
        if ($license->status !== LicenseStatus::Blocked) {
            throw new ApiException('messages.licenses.only_blocked_can_unblock', 422);
        }
    }

    public function resolveUnblockStatus(License $license): LicenseStatus
    {
        $today = app(BusinessClock::class)->now()->toDateString();

        if ($license->expiry_date !== null && $license->expiry_date->toDateString() < $today) {
            return LicenseStatus::Expired;
        }

        return LicenseStatus::Active;
    }

    public function assertCanBecomeSuccessor(License $old): void
    {
        if ($old->replacedBy()->exists()) {
            throw new ApiException('messages.licenses.already_has_successor', 422);
        }
    }

    public function statusLabel(LicenseStatus|string $status): string
    {
        $value = $status instanceof LicenseStatus ? $status->value : $status;

        return Msg::get('licenses.statuses.'.$value);
    }
}
