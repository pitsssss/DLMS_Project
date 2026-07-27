<?php

namespace App\Modules\Dashboard\Resources;

use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class DashboardPaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'status' => DashboardPaymentPresenter::paymentStatus($this->status),
            'provider' => DashboardPaymentPresenter::provider((string) $this->provider),
            'provider_reference' => $this->provider_reference,
            'amount' => DashboardPaymentPresenter::money($this->amount),
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure' => $this->failure_code ? [
                'code' => $this->failure_code,
                'message' => $this->failure_message ?? __('messages.payments.failure_codes.'.$this->failure_code),
            ] : null,
        ];
    }
}
