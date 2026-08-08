<?php

namespace App\Modules\Payments\Resources;

use App\Support\CitizenCatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'fee' => $this->whenLoaded('fee', function () {
                return [
                    'id' => $this->fee->id,
                    'name' => CitizenCatalogLabel::fee((string) $this->fee->code, $this->fee->name),
                    'code' => $this->fee->code,
                ];
            }),
        ];
    }
}
