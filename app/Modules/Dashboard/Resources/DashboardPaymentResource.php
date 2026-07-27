<?php

namespace App\Modules\Dashboard\Resources;

use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class DashboardPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staleMinutes = (int) config('payment.reconciliation.stale_pending_minutes', 60);
        $isStale = $this->isActiveAttempt()
            && $this->created_at
            && $this->created_at->lte(now()->subMinutes($staleMinutes));

        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'amount' => DashboardPaymentPresenter::money($this->amount),
            'currency' => $this->currency,
            'status' => DashboardPaymentPresenter::paymentStatus($this->status),
            'provider' => DashboardPaymentPresenter::provider((string) $this->provider),
            'provider_reference' => $this->provider_reference,
            'application' => $this->whenLoaded('application', fn () => $this->application ? [
                'id' => $this->application->id,
                'application_number' => $this->application->application_number,
                'status' => DashboardPaymentPresenter::applicationStatus($this->application->status),
            ] : null),
            'citizen' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'service_type' => $this->when(
                $this->relationLoaded('application') && $this->application?->relationLoaded('serviceType'),
                fn () => $this->application?->serviceType ? [
                    'code' => $this->application->serviceType->code,
                    'name' => EmployeeMessageTranslator::get('employee.services.'.$this->application->serviceType->code),
                ] : null
            ),
            'license_type' => $this->when(
                $this->relationLoaded('application') && $this->application?->relationLoaded('licenseType'),
                fn () => $this->application?->licenseType ? [
                    'code' => $this->application->licenseType->code,
                    'name' => EmployeeMessageTranslator::get('employee.license_types.'.$this->application->licenseType->code),
                ] : null
            ),
            'fee' => $this->whenLoaded('fee', fn () => $this->fee ? [
                'id' => $this->fee->id,
                'code' => $this->fee->code,
                'name' => $this->fee->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'is_stale_pending' => $isStale,
        ];
    }
}
