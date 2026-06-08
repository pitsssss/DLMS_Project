<?php

namespace App\Modules\Applications\Services;

use App\Enums\ApplicationStatus;
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
            return ['allowed' => false, 'message' => 'هذه الرخصة لا تخصك.'];
        }

        if ($license->status === LicenseStatus::Blocked) {
            return ['allowed' => false, 'message' => 'لا يمكن تنفيذ هذه الخدمة على رخصة محظورة.'];
        }

        if (in_array($license->status, [LicenseStatus::Suspended, LicenseStatus::Inactive, LicenseStatus::Renewed], true)) {
            return ['allowed' => false, 'message' => 'حالة الرخصة الحالية لا تسمح بتنفيذ هذه الخدمة.'];
        }

        return match ($service) {
            ServiceCode::RenewLicense => $this->checkRenewal($license),
            ServiceCode::LostReplacement => $this->checkReplacement($license, 'بدل فاقد'),
            ServiceCode::DamagedReplacement => $this->checkReplacement($license, 'بدل تالف'),
            ServiceCode::LicenseUnblock => $this->checkUnblock($license),
            default => ['allowed' => false, 'message' => 'نوع الخدمة غير مدعوم لهذه الرخصة.'],
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
            return ['allowed' => false, 'message' => 'لا يمكن تجديد الرخصة في حالتها الحالية.'];
        }

        $graceDays = (int) config('license.renewal_grace_days', 90);
        $renewableFrom = $license->expiry_date->copy()->subDays($graceDays);

        if (now()->toDateString() < $renewableFrom->toDateString() && $license->status === LicenseStatus::Active) {
            return ['allowed' => false, 'message' => 'لا يمكن تجديد الرخصة قبل اقتراب موعد انتهائها.'];
        }

        if ($this->citizenHasNewerActiveLicense($license)) {
            return ['allowed' => false, 'message' => 'توجد رخصة أحدث فعالة لنفس النوع.'];
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * @return array{allowed: bool, message: ?string}
     */
    private function checkReplacement(License $license, string $label): array
    {
        if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
            return ['allowed' => false, 'message' => "لا يمكن طلب {$label} في حالة الرخصة الحالية."];
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * @return array{allowed: bool, message: ?string}
     */
    private function checkUnblock(License $license): array
    {
        if ($license->status !== LicenseStatus::Blocked) {
            return ['allowed' => false, 'message' => 'يمكن طلب فك الحظر فقط للرخص المحظورة.'];
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
