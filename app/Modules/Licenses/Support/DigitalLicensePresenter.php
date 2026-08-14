<?php

namespace App\Modules\Licenses\Support;

use App\Models\License;
use App\Support\Msg;

final class DigitalLicensePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(License $license): array
    {
        $license->loadMissing(['citizen:id,name', 'licenseType:id,code,name']);

        $verificationUrl = self::verificationPublicUrl($license);

        return [
            'authority' => Msg::get('licenses.digital.authority'),
            'title' => Msg::get('licenses.digital.title'),
            'license_number' => $license->license_number,
            'holder_name' => $license->citizen?->name,
            'license_type' => $license->licenseType ? [
                'code' => $license->licenseType->code,
                'label' => $license->licenseType->name,
            ] : null,
            'issue_date' => $license->issue_date?->format('Y-m-d'),
            'expiry_date' => $license->expiry_date?->format('Y-m-d'),
            'status' => LicenseEffectiveStatus::value($license),
            'status_label' => LicenseEffectiveStatus::label($license),
            'verification_url' => $verificationUrl,
            'verification_guidance' => Msg::get('licenses.digital.verification_guidance'),
            'days_remaining' => LicenseEffectiveStatus::daysRemaining($license),
            'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($license),
        ];
    }

    /**
     * Public frontend verification URL encoded in the printed license QR.
     * Does not include license id or license number.
     */
    public static function verificationPublicUrl(License $license): ?string
    {
        $token = $license->verification_token;
        if (! is_string($token) || $token === '') {
            return null;
        }

        return rtrim((string) config('license.verification_public_url'), '/').'/'.$token;
    }
}
