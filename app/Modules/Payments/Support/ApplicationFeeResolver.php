<?php

namespace App\Modules\Payments\Support;

use App\Exceptions\ApiException;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Modules\Applications\Support\ServiceWorkflow;

class ApplicationFeeResolver
{
    public function resolve(LicenseApplication $application): Fee
    {
        $application->loadMissing('serviceType');

        $feeCode = ServiceWorkflow::feeCode($application->serviceType?->code);
        $serviceTypeId = $application->service_type_id;

        $fee = Fee::query()
            ->where('code', $feeCode)
            ->where('is_active', true)
            ->where(function ($query) use ($application, $serviceTypeId): void {
                $query->where(function ($scoped) use ($application, $serviceTypeId): void {
                    $scoped->where('license_type_id', $application->license_type_id)
                        ->where('service_type_id', $serviceTypeId);
                })->orWhere(function ($scoped) use ($serviceTypeId): void {
                    $scoped->whereNull('license_type_id')
                        ->where('service_type_id', $serviceTypeId);
                });
            })
            ->orderByRaw('license_type_id IS NULL')
            ->first();

        if ($fee === null && $feeCode !== 'application_fee') {
            $fee = Fee::query()
                ->where('code', $feeCode)
                ->where('is_active', true)
                ->where('service_type_id', $serviceTypeId)
                ->whereNull('license_type_id')
                ->first();
        }

        if ($fee === null) {
            throw new ApiException('messages.payments.no_fee_configured', 422);
        }

        return $fee;
    }

    public function feeCodeForApplication(LicenseApplication $application): string
    {
        $application->loadMissing('serviceType');

        return ServiceWorkflow::feeCode($application->serviceType?->code);
    }
}
