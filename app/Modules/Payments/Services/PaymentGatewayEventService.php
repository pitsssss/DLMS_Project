<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentGatewayEventStatus;
use App\Models\PaymentGatewayEvent;
use Throwable;

class PaymentGatewayEventService
{
    /**
     * Reserve a gateway event. Returns null when duplicate (already exists).
     */
    public function reserve(string $provider, string $eventId, string $eventType, ?string $payloadHash = null): ?PaymentGatewayEvent
    {
        try {
            return PaymentGatewayEvent::query()->create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payment_id' => null,
                'processing_status' => PaymentGatewayEventStatus::Received,
                'payload_hash' => $payloadHash,
                'safe_error_code' => null,
                'received_at' => now(),
                'processed_at' => null,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function markProcessed(PaymentGatewayEvent $event, ?int $paymentId = null): void
    {
        $event->processing_status = PaymentGatewayEventStatus::Processed;
        $event->processed_at = now();
        if ($paymentId !== null) {
            $event->payment_id = $paymentId;
        }
        $event->save();
    }

    public function markIgnored(PaymentGatewayEvent $event, ?string $safeErrorCode = null, ?int $paymentId = null): void
    {
        $event->processing_status = PaymentGatewayEventStatus::Ignored;
        $event->safe_error_code = $safeErrorCode;
        $event->processed_at = now();
        if ($paymentId !== null) {
            $event->payment_id = $paymentId;
        }
        $event->save();
    }

    public function markFailed(PaymentGatewayEvent $event, string $safeErrorCode, ?int $paymentId = null): void
    {
        $event->processing_status = PaymentGatewayEventStatus::Failed;
        $event->safe_error_code = $safeErrorCode;
        $event->processed_at = now();
        if ($paymentId !== null) {
            $event->payment_id = $paymentId;
        }
        $event->save();
    }
}
