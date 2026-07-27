<?php

namespace App\Modules\Dashboard\Resources;

use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardDueFeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fee = $this->resource['fee'];
        $application = $this->resource['application'];
        $latest = $this->resource['latest_attempt'] ?? null;

        return [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => DashboardPaymentPresenter::applicationStatus($application->status),
            ],
            'citizen' => [
                'id' => $application->citizen?->id,
                'name' => $application->citizen?->name,
            ],
            'service_type' => $application->serviceType ? [
                'code' => $application->serviceType->code,
                'name' => EmployeeMessageTranslator::get('employee.services.'.$application->serviceType->code),
            ] : null,
            'license_type' => $application->licenseType ? [
                'code' => $application->licenseType->code,
                'name' => EmployeeMessageTranslator::get('employee.license_types.'.$application->licenseType->code),
            ] : null,
            'fee' => [
                'id' => $fee->id,
                'code' => $fee->code,
                'name' => $fee->name,
                'amount' => DashboardPaymentPresenter::money($fee->amount),
                'currency' => $fee->currency,
            ],
            'attempts_count' => (int) ($this->resource['attempts_count'] ?? 0),
            'latest_attempt' => $latest ? [
                'id' => $latest->id,
                'payment_number' => $latest->payment_number,
                'status' => DashboardPaymentPresenter::paymentStatus($latest->status),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'due_since' => $application->updated_at?->toIso8601String()
                ?? $application->submitted_at?->toIso8601String()
                ?? $application->created_at?->toIso8601String(),
        ];
    }
}
