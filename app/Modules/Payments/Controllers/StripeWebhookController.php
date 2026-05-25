<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\ApplicationPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, ApplicationPaymentService $payments): Response
    {
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

        $session = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                if ($session instanceof CheckoutSession && $session->payment_status === 'paid') {
                    $payments->completeStripePaymentFromSession($session, $event->id);
                }
                break;

            case 'checkout.session.async_payment_failed':
                if ($session instanceof CheckoutSession) {
                    $payments->handleStripeCheckoutSessionFailed($session, 'async_payment_failed', $event->id);
                }
                break;

            case 'checkout.session.expired':
                if ($session instanceof CheckoutSession) {
                    $payments->handleStripeCheckoutSessionFailed($session, 'expired', $event->id);
                }
                break;

            default:
                break;
        }

        return response('OK', 200);
    }
}
