<?php

namespace App\Modules\Payments\Support;

use App\Models\Fee;

final class FeeIdentity
{
    public static function buildKey(
        string $code,
        ?int $licenseTypeId,
        ?int $serviceTypeId,
        ?int $testTypeId,
    ): string {
        return sprintf(
            '%s|lt:%d|st:%d|tt:%d',
            trim($code),
            $licenseTypeId ?? 0,
            $serviceTypeId ?? 0,
            $testTypeId ?? 0,
        );
    }

    public static function keyForFee(Fee $fee): string
    {
        return self::buildKey(
            (string) $fee->code,
            $fee->license_type_id !== null ? (int) $fee->license_type_id : null,
            $fee->service_type_id !== null ? (int) $fee->service_type_id : null,
            $fee->test_type_id !== null ? (int) $fee->test_type_id : null,
        );
    }

    /**
     * @return array{license_type_id: ?int, service_type_id: ?int, test_type_id: ?int}
     */
    public static function normalizedScope(
        ?int $licenseTypeId,
        ?int $serviceTypeId,
        ?int $testTypeId,
    ): array {
        return [
            'license_type_id' => $licenseTypeId !== null && $licenseTypeId > 0 ? $licenseTypeId : null,
            'service_type_id' => $serviceTypeId !== null && $serviceTypeId > 0 ? $serviceTypeId : null,
            'test_type_id' => $testTypeId !== null && $testTypeId > 0 ? $testTypeId : null,
        ];
    }
}
