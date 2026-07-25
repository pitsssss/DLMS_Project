<?php

namespace App\Modules\Applications\Support;

use App\Enums\ServiceCode;
use App\Models\ServiceType;

/**
 * Authoritative workflow capabilities for known ServiceCode values.
 *
 * Unknown / custom dashboard service codes are fail-closed: not supported,
 * not issuable, and do not inherit new-license behavior.
 */
class ServiceWorkflow
{
    public static function tryFromCode(?string $code): ?ServiceCode
    {
        if ($code === null || $code === '') {
            return null;
        }

        return ServiceCode::tryFrom($code);
    }

    public static function fromServiceType(?ServiceType $serviceType): ?ServiceCode
    {
        return self::tryFromCode($serviceType?->code);
    }

    /**
     * @return list<string>
     */
    public static function issuableCodes(): array
    {
        return array_map(
            static fn (ServiceCode $code): string => $code->value,
            [
                ServiceCode::NewLicense,
                ServiceCode::RenewLicense,
                ServiceCode::LostReplacement,
                ServiceCode::DamagedReplacement,
            ]
        );
    }

    public static function isSupportedWorkflow(ServiceCode|string|null $code): bool
    {
        return ($code instanceof ServiceCode ? $code : self::tryFromCode($code)) !== null;
    }

    public static function producesLicense(ServiceCode|string|null $code): bool
    {
        $service = $code instanceof ServiceCode ? $code : self::tryFromCode($code);

        if ($service === null) {
            return false;
        }

        return in_array($service, [
            ServiceCode::NewLicense,
            ServiceCode::RenewLicense,
            ServiceCode::LostReplacement,
            ServiceCode::DamagedReplacement,
        ], true);
    }

    public static function usesUnblockWorkflow(ServiceCode|string|null $code): bool
    {
        $service = $code instanceof ServiceCode ? $code : self::tryFromCode($code);

        return $service === ServiceCode::LicenseUnblock;
    }

    public static function requiresRelatedLicense(ServiceCode|string|null $code): bool
    {
        $service = $code instanceof ServiceCode ? $code : self::tryFromCode($code);

        if ($service === null) {
            return false;
        }

        return in_array($service, [
            ServiceCode::RenewLicense,
            ServiceCode::LostReplacement,
            ServiceCode::DamagedReplacement,
            ServiceCode::LicenseUnblock,
        ], true);
    }

    public static function requiresTests(ServiceCode|string|null $code): bool
    {
        $service = $code instanceof ServiceCode ? $code : self::tryFromCode($code);

        // Fail-closed: only explicit new_license requires tests.
        return $service === ServiceCode::NewLicense;
    }

    public static function feeCode(ServiceCode|string|null $code): string
    {
        $service = $code instanceof ServiceCode ? $code : self::tryFromCode($code);

        return match ($service) {
            ServiceCode::RenewLicense => 'renewal_fee',
            ServiceCode::LostReplacement => 'lost_replacement_fee',
            ServiceCode::DamagedReplacement => 'damaged_replacement_fee',
            ServiceCode::LicenseUnblock => 'unblock_fee',
            default => 'application_fee',
        };
    }
}
