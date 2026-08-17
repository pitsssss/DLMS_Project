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
            'is_valid' => LicenseEffectiveStatus::isValidForVerification($license),
            'has_portrait' => app(LicensePortraitResolver::class)->hasPortrait($license),
            'verification_url' => $verificationUrl,
            'verification_host' => self::officialVerificationHost($verificationUrl),
            'verification_guidance' => Msg::get('licenses.digital.verification_guidance'),
            'days_remaining' => LicenseEffectiveStatus::daysRemaining($license),
            'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($license),
            'labels' => self::labels(),
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

    public static function officialVerificationHost(?string $verificationUrl): ?string
    {
        if (! is_string($verificationUrl) || $verificationUrl === '') {
            return null;
        }

        $host = parse_url($verificationUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $normalized = strtolower($host);
        if (in_array($normalized, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return null;
        }

        if (str_ends_with($normalized, '.local') || str_ends_with($normalized, '.test')) {
            return null;
        }

        return $host;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'name' => Msg::get('licenses.digital.fields.name'),
            'license_number' => Msg::get('licenses.digital.fields.license_number'),
            'type' => Msg::get('licenses.digital.fields.type'),
            'issue_date' => Msg::get('licenses.digital.fields.issue_date'),
            'expiry_date' => Msg::get('licenses.digital.fields.expiry_date'),
            'status' => Msg::get('licenses.digital.fields.status'),
            'front' => Msg::get('licenses.digital.front'),
            'back' => Msg::get('licenses.digital.back'),
            'invalid' => Msg::get('licenses.digital.invalid'),
            'verify_heading' => Msg::get('licenses.digital.verify_heading'),
            'verify_instruction' => Msg::get('licenses.digital.verify_instruction'),
            'official_use' => Msg::get('licenses.digital.official_use'),
            'verify_host_fallback' => Msg::get('licenses.digital.verify_host_fallback'),
        ];
    }
}
