<?php

namespace App\Modules\Applications\Services;

use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Models\License;
use App\Models\User;

class LicenseServiceEligibilityService
{
    /**
     * @return array{allowed: bool, message: ?string}
     */
    public function check(User $citizen, License $license, ServiceCode $service): array
    {
        if ((int) $license->citizen_id !== (int) $citizen->id) {
            return ['allowed' => false, 'message' => 'messages.licenses.eligibility.not_owned'];
        }

        if ($license->status === LicenseStatus::Blocked) {
            return ['allowed' => false, 'message' => 'messages.licenses.eligibility.blocked_service'];
        }

        if (in_array($license->status, [LicenseStatus::Suspended, LicenseStatus::Inactive, LicenseStatus::Renewed], true)) {
            return ['allowed' => false, 'message' => 'messages.licenses.eligibility.status_not_allowed'];
        }

        return match ($service) {
            ServiceCode::RenewLicense => $this->checkRenewal($license),
            ServiceCode::LostReplacement => $this->checkReplacement(
                $license,
                'messages.licenses.eligibility.cannot_request_lost_replacement'
            ),
            ServiceCode::DamagedReplacement => $this->checkReplacement(
                $license,
                'messages.licenses.eligibility.cannot_request_damaged_replacement'
            ),
            ServiceCode::LicenseUnblock => $this->checkUnblock($license),
            default => ['allowed' => false, 'message' => 'messages.licenses.eligibility.unsupported_service'],
        };
    }

    /**
     * @return array{can_renew: bool, can_request_lost_replacement: bool, can_request_damaged_replacement: bool}
     */
    public function flagsForCitizen(User $citizen, License $license): array
    {
        return [
            'can_renew' => $this->check($citizen, $license, ServiceCode::RenewLicense)['allowed'],
            'can_request_lost_replacement' => $this->check($citizen, $license, ServiceCode::LostReplacement)['allowed'],
            'can_request_damaged_replacement' => $this->check($citizen, $license, ServiceCode::DamagedReplacement)['allowed'],
        ];
    }

    /**
     * @return array{allowed: bool, message: ?string}
     */
    private function checkRenewal(License $license): array
    {
        if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
            return ['allowed' => false, 'message' => 'messages.licenses.cannot_renew_status'];
        }

        $graceDays = (int) config('license.renewal_grace_days', 90);
        $renewableFrom = $license->expiry_date->copy()->subDays($graceDays);

        if (now()->toDateString() < $renewableFrom->toDateString() && $license->status === LicenseStatus::Active) {
            return ['allowed' => false, 'message' => 'messages.licenses.eligibility.renewal_too_early'];
        }

        if ($this->citizenHasNewerActiveLicense($license)) {
            return ['allowed' => false, 'message' => 'messages.licenses.eligibility.newer_active_exists'];
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * @return array{allowed: bool, message: ?string}
     */
    private function checkReplacement(License $license, string $messageKey): array
    {
        if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
            return ['allowed' => false, 'message' => $messageKey];
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * @return array{allowed: bool, message: ?string}
     */
    private function checkUnblock(License $license): array
    {
        if ($license->status !== LicenseStatus::Blocked) {
            return ['allowed' => false, 'message' => 'messages.licenses.only_blocked_unblock'];
        }

        return ['allowed' => true, 'message' => null];
    }

    private function citizenHasNewerActiveLicense(License $license): bool
    {
        return License::query()
            ->where('citizen_id', $license->citizen_id)
            ->where('license_type_id', $license->license_type_id)
            ->where('id', '>', $license->id)
            ->whereIn('status', [LicenseStatus::Active->value, LicenseStatus::Expired->value])
            ->exists();
    }
}
