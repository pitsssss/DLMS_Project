<?php

namespace App\Modules\Payments\Support;

use App\Models\Payment;
use App\Support\CitizenCatalogLabel;
use App\Support\CitizenMessageTranslator;

/**
 * Resolves citizen-facing payment purpose + related metadata from persisted Payment relations.
 */
final class CitizenPaymentPurposeResolver
{
    /**
     * @return array{
     *   purpose: array{code: string, label: string},
     *   related: array<string, mixed>
     * }|null
     */
    public function resolve(Payment $payment): ?array
    {
        if ($payment->isFinePayment()) {
            return $this->resolveFine($payment);
        }

        if ($payment->isApplicationPayment()) {
            return $this->resolveApplication($payment);
        }

        return null;
    }

    /**
     * @return array{purpose: array{code: string, label: string}, related: array<string, mixed>}
     */
    private function resolveFine(Payment $payment): array
    {
        $payment->loadMissing('fine');

        $related = [
            'type' => 'fine',
            'id' => (int) $payment->fine_id,
        ];

        if ($payment->fine !== null) {
            $related['fine_status'] = $payment->fine->status->value;
        }

        return [
            'purpose' => [
                'code' => 'fine',
                'label' => CitizenMessageTranslator::get('messages.payments.purposes.fine'),
            ],
            'related' => $related,
        ];
    }

    /**
     * @return array{purpose: array{code: string, label: string}, related: array<string, mixed>}
     */
    private function resolveApplication(Payment $payment): array
    {
        $payment->loadMissing(['fee', 'application.serviceType']);

        $feeCode = $payment->fee?->code;
        if (! is_string($feeCode) || $feeCode === '') {
            $feeCode = 'application_fee';
        }

        $related = [
            'type' => 'application',
            'id' => (int) $payment->application_id,
        ];

        if ($payment->application !== null) {
            $related['application_number'] = $payment->application->application_number;
            $serviceCode = $payment->application->serviceType?->code;
            if (is_string($serviceCode) && $serviceCode !== '') {
                $related['service_type_code'] = $serviceCode;
            }
        }

        if ($payment->fee !== null) {
            $related['fee_code'] = $payment->fee->code;
        }

        return [
            'purpose' => [
                'code' => $feeCode,
                'label' => CitizenCatalogLabel::fee($feeCode, $payment->fee?->name),
            ],
            'related' => $related,
        ];
    }
}
