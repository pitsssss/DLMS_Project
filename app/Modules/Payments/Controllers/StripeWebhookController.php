<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\ApplicationPaymentService;
use App\Modules\Payments\Services\PaymentGatewayEventService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(
        Request $request,
        ApplicationPaymentService $payments,
        PaymentGatewayEventService $gatewayEvents
    ): Response {
        $payload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature', '');
        $secret = (string) config('payment.stripe.webhook_secret');

        if ($secret === '') {
            return response(__('messages.payments.webhook_not_configured'), 503);
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response(__('messages.payments.webhook_invalid_signature'), 400);
        }

        $payloadHash = hash('sha256', $payload);
        $reserved = $gatewayEvents->reserve('stripe', (string) $event->id, (string) $event->type, $payloadHash);

        if ($reserved === null) {
            return response('OK', 200);
        }

        $session = $event->data->object;

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    if ($session instanceof CheckoutSession && $session->payment_status === 'paid') {
                        $payments->completeStripePaymentFromSession($session, $event->id);
                        $payment = $payments->findStripePaymentBySessionId($session->id)
                            ?? $payments->findStripePaymentBySessionMetadata($session->metadata);
                        $gatewayEvents->markProcessed($reserved, $payment?->id);
                    } else {
                        $gatewayEvents->markIgnored($reserved, 'not_paid');
                    }
                    break;

                case 'checkout.session.async_payment_failed':
                    if ($session instanceof CheckoutSession) {
                        $payments->handleStripeCheckoutSessionFailed($session, 'async_payment_failed', $event->id);
                        $payment = $payments->findStripePaymentBySessionId($session->id)
                            ?? $payments->findStripePaymentBySessionMetadata($session->metadata);
                        $gatewayEvents->markProcessed($reserved, $payment?->id);
                    } else {
                        $gatewayEvents->markIgnored($reserved);
                    }
                    break;

                case 'checkout.session.expired':
                    if ($session instanceof CheckoutSession) {
                        $payments->handleStripeCheckoutSessionFailed($session, 'expired', $event->id);
                        $payment = $payments->findStripePaymentBySessionId($session->id)
                            ?? $payments->findStripePaymentBySessionMetadata($session->metadata);
                        $gatewayEvents->markProcessed($reserved, $payment?->id);
                    } else {
                        $gatewayEvents->markIgnored($reserved);
                    }
                    break;

                default:
                    $gatewayEvents->markIgnored($reserved, 'unhandled_event_type');
                    break;
            }
        } catch (\Throwable $e) {
            report($e);
            $gatewayEvents->markFailed($reserved, 'processing_error');
        }

        return response('OK', 200);
    }
}
