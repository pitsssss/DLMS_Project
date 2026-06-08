<?php

namespace App\Modules\Applications\Support;

use App\Enums\ServiceCode;
use App\Models\ServiceType;

class ServiceWorkflow
{
    public static function tryFromCode(?string $code): ?ServiceCode
    {
        if ($code === null || $code === '') {
            return null;
        }

        return ServiceCode::tryFrom($code);
    }

    public static function fromServiceType(ServiceType $serviceType): ?ServiceCode
    {
        return self::tryFromCode($serviceType->code);
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

        return $service === null || $service === ServiceCode::NewLicense;
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
