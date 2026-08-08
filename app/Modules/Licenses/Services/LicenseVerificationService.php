<?php

namespace App\Modules\Licenses\Services;

use App\Models\License;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use App\Support\BusinessClock;
use App\Support\CitizenCatalogLabel;
use App\Support\CitizenMessageTranslator;

class LicenseVerificationService
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        $token = trim($token);
        $verifiedAt = app(BusinessClock::class)->now()->toIso8601String();

        if ($token === '' || strlen($token) < 20) {
            return $this->invalid($verifiedAt);
        }

        $license = License::query()
            ->where('verification_token', $token)
            ->with(['citizen:id,name', 'licenseType:id,code,name'])
            ->first();

        if ($license === null) {
            return $this->invalid($verifiedAt);
        }

        $status = LicenseEffectiveStatus::resolve($license);
        $valid = LicenseEffectiveStatus::isValidForVerification($license);

        return [
            'valid' => $valid,
            'status' => $status->value,
            'status_label' => CitizenMessageTranslator::get('messages.licenses.statuses.'.$status->value),
            'license_number' => $license->license_number,
            'holder_name' => $license->citizen?->name,
            'license_type' => $license->licenseType ? [
                'code' => $license->licenseType->code,
                'label' => CitizenCatalogLabel::licenseType(
                    (string) $license->licenseType->code,
                    $license->licenseType->name
                ),
            ] : null,
            'issue_date' => $license->issue_date?->format('Y-m-d'),
            'expiry_date' => $license->expiry_date?->format('Y-m-d'),
            'message' => $valid
                ? CitizenMessageTranslator::get('messages.licenses.verification.valid')
                : CitizenMessageTranslator::get('messages.licenses.verification.invalid_status'),
            'verified_at' => $verifiedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $verifiedAt): array
    {
        return [
            'valid' => false,
            'status' => null,
            'status_label' => null,
            'license_number' => null,
            'holder_name' => null,
            'license_type' => null,
            'issue_date' => null,
            'expiry_date' => null,
            'message' => CitizenMessageTranslator::get('messages.licenses.verification.not_found'),
            'verified_at' => $verifiedAt,
        ];
    }
}
