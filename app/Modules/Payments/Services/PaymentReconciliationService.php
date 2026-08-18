<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Support\Money;
use App\Services\AuditLogService;
use Stripe\Checkout\Session as CheckoutSession;
use Throwable;

class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentLifecycleService $lifecycle,
        private readonly StripePaymentGatewayService $stripeGateway,
        private readonly PaymentProviderManager $providers,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array{payment: Payment, result: string}
     */
    public function reconcile(Payment $payment, ?User $actor = null, string $source = 'dashboard'): array
    {
        if (! $payment->isSupportedPayable()) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        if ($payment->status === PaymentStatus::Completed) {
            return ['payment' => $payment->fresh(['fee', 'application', 'user']), 'result' => 'already_completed'];
        }

        if ($payment->provider === 'mock' || $this->providers->isMock()) {
            throw new ApiException('messages.payments.verification_unsupported_for_provider', 422);
        }

        if (! is_string($payment->provider_reference) || $payment->provider_reference === '') {
            throw new ApiException('messages.payments.missing_provider_reference', 422);
        }

        try {
            $session = $this->stripeGateway->retrieveCheckoutSession($payment->provider_reference);
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('messages.payments.provider_unavailable', 503);
        }

        $payment->last_verified_at = now();
        $payment->save();

        $this->auditLogs->log(
            $actor,
            'payment.verified',
            'payment',
            $payment->id,
            ['status' => $payment->status->value],
            [
                'status' => $payment->status->value,
                'source' => $source,
                'provider_payment_status' => $session->payment_status ?? null,
                'provider_session_status' => $session->status ?? null,
            ]
        );

        if (($session->payment_status ?? null) === 'paid') {
            $validation = $this->validateStripeSession($session, $payment);
            if ($validation !== null) {
                $updated = $this->lifecycle->markUnderVerification(
                    $payment->id,
                    $validation,
                    ['stripe_event_source' => 'reconciliation'],
                    $actor,
                    $source
                );

                return ['payment' => $updated, 'result' => 'under_verification'];
            }

            $updated = $this->lifecycle->completeVerifiedPayment(
                $payment->id,
                $actor,
                [
                    'stripe_session_id' => $session->id,
                    'stripe_payment_status' => $session->payment_status,
                    'stripe_session_status' => $session->status,
                    'stripe_event_source' => 'reconciliation',
                ],
                $session->id,
                $source
            );

            return ['payment' => $updated, 'result' => 'completed'];
        }

        if (($session->status ?? null) === 'expired') {
            $updated = $this->lifecycle->markFailed(
                $payment->id,
                PaymentFailureCode::SessionExpired,
                ['stripe_session_status' => $session->status],
                $actor,
                $source
            );

            return ['payment' => $updated, 'result' => 'failed'];
        }

        $updated = $this->lifecycle->markUnderVerification(
            $payment->id,
            PaymentFailureCode::VerificationFailed,
            [
                'stripe_payment_status' => $session->payment_status ?? null,
                'stripe_session_status' => $session->status ?? null,
            ],
            $actor,
            $source
        );

        return ['payment' => $updated, 'result' => 'under_verification'];
    }

    public function validateStripeSession(CheckoutSession $session, Payment $payment): ?PaymentFailureCode
    {
        $expectedCurrency = strtoupper((string) $payment->currency);
        $sessionCurrency = strtoupper((string) ($session->currency ?? ''));
        $configured = strtoupper((string) config('payment.stripe.currency', 'usd'));

        if ($sessionCurrency !== '' && $sessionCurrency !== $expectedCurrency) {
            return PaymentFailureCode::CurrencyMismatch;
        }

        if ($configured !== $expectedCurrency) {
            return PaymentFailureCode::CurrencyMismatch;
        }

        $actual = (int) ($session->amount_total ?? 0);
        if ($actual > 0) {
            $expectedMinor = Money::toMinorUnits((string) $payment->amount, $expectedCurrency);
            if ($actual !== $expectedMinor) {
                return PaymentFailureCode::AmountMismatch;
            }
        }

        return null;
    }
}
