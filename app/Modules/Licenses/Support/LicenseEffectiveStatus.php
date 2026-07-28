<?php

namespace App\Modules\Licenses\Support;

use App\Enums\LicenseStatus;
use App\Models\License;
use App\Support\BusinessClock;
use App\Support\Msg;
use Carbon\CarbonInterface;

final class LicenseEffectiveStatus
{
    public static function resolve(License $license, ?CarbonInterface $asOf = null): LicenseStatus
    {
        $stored = $license->status instanceof LicenseStatus
            ? $license->status
            : LicenseStatus::tryFrom((string) $license->status);

        if ($stored === null) {
            return LicenseStatus::Inactive;
        }

        if ($stored === LicenseStatus::Active && self::isPastExpiry($license, $asOf)) {
            return LicenseStatus::Expired;
        }

        return $stored;
    }

    public static function value(License $license, ?CarbonInterface $asOf = null): string
    {
        return self::resolve($license, $asOf)->value;
    }

    public static function label(License $license, ?CarbonInterface $asOf = null): string
    {
        return Msg::get('licenses.statuses.'.self::value($license, $asOf));
    }

    public static function isValidForVerification(License $license, ?CarbonInterface $asOf = null): bool
    {
        return self::resolve($license, $asOf) === LicenseStatus::Active;
    }

    public static function isPastExpiry(License $license, ?CarbonInterface $asOf = null): bool
    {
        if ($license->expiry_date === null) {
            return false;
        }

        $today = ($asOf ?? app(BusinessClock::class)->now())->toDateString();

        return $license->expiry_date->toDateString() < $today;
    }

    public static function daysRemaining(License $license, ?CarbonInterface $asOf = null): ?int
    {
        if ($license->expiry_date === null) {
            return null;
        }

        $today = ($asOf ?? app(BusinessClock::class)->now())->startOfDay();
        $expiry = $license->expiry_date->copy()->startOfDay();

        return (int) $today->diffInDays($expiry, false);
    }

    public static function isExpiringSoon(License $license, int $withinDays = 90, ?CarbonInterface $asOf = null): bool
    {
        if (self::resolve($license, $asOf) !== LicenseStatus::Active) {
            return false;
        }

        $days = self::daysRemaining($license, $asOf);

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }
}
