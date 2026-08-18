<?php

namespace App\Modules\Payments\Resources;

use App\Modules\Payments\Support\CitizenPaymentPurposeResolver;
use App\Support\CitizenMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class CitizenPaymentResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolved = app(CitizenPaymentPurposeResolver::class)->resolve($this->resource);

        $payload = [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => CitizenMessageTranslator::get('messages.payments.statuses.'.$this->status->value),
            'provider' => $this->provider,
            'purpose' => $resolved['purpose'] ?? [
                'code' => 'unknown',
                'label' => CitizenMessageTranslator::get('messages.payments.purposes.unknown'),
            ],
            'related' => $resolved['related'] ?? null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($this->detailed) {
            $payload['detail'] = $this->detailPayload();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detailPayload(): ?array
    {
        if ($this->resource->isFinePayment()) {
            $this->resource->loadMissing('fine.license');
            $fine = $this->fine;
            if ($fine === null) {
                return null;
            }

            return [
                'fine' => [
                    'id' => $fine->id,
                    'amount' => $fine->amount,
                    'currency' => $fine->currency,
                    'reason' => $fine->reason,
                    'status' => $fine->status->value,
                    'paid_at' => $fine->paid_at?->toIso8601String(),
                    'license_id' => $fine->license_id,
                    'license_number' => $fine->license?->license_number,
                ],
            ];
        }

        if ($this->resource->isApplicationPayment()) {
            $this->resource->loadMissing(['fee', 'application.serviceType']);
            $application = $this->application;
            $fee = $this->fee;

            return [
                'application' => $application === null ? null : [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'status' => $application->status->value,
                    'service_type_code' => $application->serviceType?->code,
                ],
                'fee' => $fee === null ? null : [
                    'id' => $fee->id,
                    'code' => $fee->code,
                    'name' => \App\Support\CitizenCatalogLabel::fee((string) $fee->code, $fee->name),
                ],
            ];
        }

        return null;
    }
}
