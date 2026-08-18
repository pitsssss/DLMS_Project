<?php

namespace App\Modules\Payments\Services;

use App\Enums\FineStatus;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Support\Money;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as CheckoutSession;
use Throwable;

class FinePaymentService
{
    public function __construct(
        private readonly PaymentProviderManager $providerManager,
        private readonly StripePaymentGatewayService $stripeGateway,
        private readonly AuditLogService $auditLogs,
        private readonly PaymentLifecycleService $lifecycle,
        private readonly PaymentReconciliationService $reconciliation,
    ) {}

    public function requireOwnedFine(User $citizen, int $fineId): Fine
    {
        $fine = Fine::query()
            ->whereKey($fineId)
            ->where('citizen_id', $citizen->id)
            ->with('license')
            ->first();

        if ($fine === null) {
            throw new ApiException('messages.fines.not_found', 404);
        }

        return $fine;
    }

    /**
     * @return array{payment: Payment, checkout_url?: string, publishable_key?: string}
     */
    public function createPendingPayment(User $citizen, int $fineId, ?array $metadata = null): array
    {
        $provider = $this->providerManager->isStripe() ? 'stripe' : 'mock';

        $prepared = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            /** @var array{action: string, payment: Payment} $prepared */
            $prepared = DB::transaction(function () use ($citizen, $fineId, $metadata, $provider) {
                $fine = Fine::query()
                    ->whereKey($fineId)
                    ->where('citizen_id', $citizen->id)
                    ->lockForUpdate()
                    ->first();

                if ($fine === null) {
                    throw new ApiException('messages.fines.not_found', 404);
                }

                $this->assertFinePayable($fine);

                $obligationKey = Payment::fineObligationKey($fine->id);

                $existingCompleted = Payment::query()
                    ->where(function ($q) use ($obligationKey, $fine): void {
                        $q->where('settled_obligation_key', $obligationKey)
                            ->orWhere(function ($inner) use ($fine): void {
                                $inner->where('fine_id', $fine->id)
                                    ->whereNull('application_id')
                                    ->where('status', PaymentStatus::Completed);
                            });
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($existingCompleted) {
                    throw new ApiException('messages.fines.payment_already_completed', 422);
                }

                $existingActive = Payment::query()
                    ->where('active_obligation_key', $obligationKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingActive !== null) {
                    if ((string) $existingActive->provider === $provider) {
                        return ['action' => 'reuse', 'payment' => $existingActive];
                    }

                    return ['action' => 'retire', 'payment' => $existingActive];
                }

                $currency = strtoupper((string) $fine->currency);
                $canonical = strtoupper((string) config('payment.fine_currency', 'USD'));
                if ($currency !== $canonical) {
                    throw new ApiException('messages.fines.currency_unsupported', 422);
                }

                $payment = Payment::query()->create([
                    'payment_number' => $this->generateUniquePaymentNumber(),
                    'user_id' => $citizen->id,
                    'application_id' => null,
                    'fine_id' => $fine->id,
                    'fee_id' => null,
                    'payable_type' => null,
                    'payable_id' => null,
                    'amount' => Money::format((string) $fine->amount),
                    'currency' => $currency,
                    'status' => PaymentStatus::Pending,
                    'provider' => $provider,
                    'provider_reference' => null,
                    'paid_at' => null,
                    'metadata' => $metadata,
                    'active_obligation_key' => $obligationKey,
                    'settled_obligation_key' => null,
                ]);

                $this->auditLogs->log(
                    $citizen,
                    'payment.created',
                    'payment',
                    $payment->id,
                    null,
                    [
                        'status' => PaymentStatus::Pending->value,
                        'amount' => Money::format((string) $payment->amount),
                        'currency' => $payment->currency,
                        'provider' => $provider,
                        'payment_number' => $payment->payment_number,
                        'fine_id' => $fine->id,
                        'source' => 'citizen',
                    ]
                );

                return ['action' => 'created', 'payment' => $payment];
            });

            if ($prepared['action'] === 'retire') {
                $this->lifecycle->retireActiveIfProviderMismatch(
                    $prepared['payment'],
                    $provider,
                    $citizen,
                    'citizen'
                );
                continue;
            }

            break;
        }

        if ($prepared === null || $prepared['action'] === 'retire') {
            throw new ApiException('messages.payments.provider_unavailable', 503);
        }

        $payment = $prepared['payment'];
        $created = $prepared['action'] === 'created';

        if ($provider !== 'stripe') {
            return ['payment' => $payment];
        }

        if (! $created && $payment->provider === 'stripe') {
            $checkoutUrl = $payment->metadata['checkout_url'] ?? null;
            if (is_string($checkoutUrl) && $checkoutUrl !== '' && $payment->provider_reference) {
                return [
                    'payment' => $payment,
                    'checkout_url' => $checkoutUrl,
                    'publishable_key' => (string) config('payment.stripe.publishable_key'),
                ];
            }
        }

        if ($payment->provider !== 'stripe') {
            throw new ApiException('messages.payments.provider_unavailable', 503);
        }

        $this->assertStripeCurrencyCompatible($payment);

        $fine = Fine::query()->findOrFail($payment->fine_id);

        try {
            $session = $this->stripeGateway->createFineCheckoutSession($payment, $fine, $citizen);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->lifecycle->markFailed(
                $payment->id,
                PaymentFailureCode::CheckoutCreationFailed,
                ['stripe_event_source' => 'checkout_create'],
                $citizen,
                'citizen'
            );
            throw new ApiException('messages.payments.provider_unavailable', 503);
        }

        DB::transaction(function () use ($payment, $session, $citizen): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->provider_reference = $session['session_id'];
            $locked->metadata = array_merge($locked->metadata ?? [], $this->stripeSessionMetadata($session['session']));
            $locked->save();

            $this->auditLogs->log(
                $citizen,
                'payment.initiated',
                'payment',
                $locked->id,
                null,
                [
                    'provider' => 'stripe',
                    'provider_reference' => $locked->provider_reference,
                    'payment_number' => $locked->payment_number,
                    'fine_id' => $locked->fine_id,
                    'source' => 'citizen',
                ]
            );
        });

        return [
            'payment' => $payment->fresh(),
            'checkout_url' => $session['url'],
            'publishable_key' => (string) config('payment.stripe.publishable_key'),
        ];
    }

    public function confirmMockPayment(User $citizen, int $fineId, int $paymentId): Payment
    {
        if ($this->providerManager->isStripe()) {
            throw new ApiException('messages.payments.manual_confirm_disabled', 400);
        }

        $fine = $this->requireOwnedFine($citizen, $fineId);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('fine_id', $fineId)
            ->where('user_id', $citizen->id)
            ->whereNull('application_id')
            ->first();

        if ($payment === null) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        if ($payment->provider !== 'mock') {
            throw new ApiException('messages.payments.manual_confirm_disabled', 400);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            throw new ApiException('messages.payments.cannot_confirm_state', 422);
        }

        return $this->lifecycle->completeVerifiedPayment(
            $payment->id,
            $citizen,
            ['source' => 'mock_confirm'],
            'mock-'.Str::uuid()->toString(),
            'citizen'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentStatus(User $citizen, int $fineId, int $paymentId): array
    {
        $fine = $this->requireOwnedFine($citizen, $fineId);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('fine_id', $fine->id)
            ->where('user_id', $citizen->id)
            ->whereNull('application_id')
            ->first();

        if ($payment === null) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        $stripePaymentStatus = null;
        $stripeSessionStatus = null;

        if ($payment->provider === 'stripe' && is_string($payment->provider_reference) && $payment->provider_reference !== '') {
            try {
                $session = $this->stripeGateway->retrieveCheckoutSession($payment->provider_reference);
                $stripePaymentStatus = $session->payment_status;
                $stripeSessionStatus = $session->status;

                $payment->metadata = array_merge($payment->metadata ?? [], $this->stripeSessionMetadata($session));
                $payment->save();

                if ($session->payment_status === 'paid' && $payment->status !== PaymentStatus::Completed) {
                    $mismatch = $this->reconciliation->validateStripeSession($session, $payment);
                    if ($mismatch !== null) {
                        $payment = $this->lifecycle->markUnderVerification(
                            $payment->id,
                            $mismatch,
                            array_merge($this->stripeSessionMetadata($session), [
                                'stripe_event_source' => 'status_poll',
                            ]),
                            $citizen,
                            'status_poll'
                        );
                    } else {
                        $payment = $this->lifecycle->completeVerifiedPayment(
                            $payment->id,
                            $citizen,
                            array_merge($this->stripeSessionMetadata($session), [
                                'stripe_event_source' => 'status_poll',
                            ]),
                            $session->id,
                            'status_poll'
                        );
                    }
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (Throwable) {
                // Stripe unreachable: return last known state
            }
        }

        $payment->refresh();
        $fine->refresh();

        return [
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            'fine' => [
                'id' => $fine->id,
                'status' => $fine->status->value,
                'paid_at' => $fine->paid_at?->toIso8601String(),
            ],
            'stripe' => [
                'payment_status' => $stripePaymentStatus,
                'session_status' => $stripeSessionStatus,
            ],
        ];
    }

    private function assertFinePayable(Fine $fine): void
    {
        if ($fine->status === FineStatus::Paid) {
            throw new ApiException('messages.fines.already_paid', 422);
        }

        if ($fine->status === FineStatus::Cancelled) {
            throw new ApiException('messages.fines.not_payable_cancelled', 422);
        }

        if ($fine->status !== FineStatus::Unpaid) {
            throw new ApiException('messages.fines.not_payable', 422);
        }

        if ((float) $fine->amount <= 0) {
            throw new ApiException('messages.fines.amount_invalid', 422);
        }
    }

    private function assertStripeCurrencyCompatible(Payment $payment): void
    {
        $paymentCurrency = strtoupper((string) $payment->currency);
        $stripeCurrency = strtoupper((string) config('payment.stripe.currency', 'usd'));

        if ($paymentCurrency !== $stripeCurrency) {
            throw new ApiException('messages.payments.provider_currency_unsupported', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeSessionMetadata(CheckoutSession $session): array
    {
        return [
            'stripe_session_id' => $session->id,
            'stripe_payment_intent' => $session['payment_intent'] ?? null,
            'stripe_payment_status' => $session['payment_status'] ?? null,
            'stripe_session_status' => $session['status'] ?? null,
            'checkout_url' => $session['url'] ?? null,
        ];
    }

    private function generateUniquePaymentNumber(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $number = 'PAY-'.now()->format('Y').'-'.strtoupper(Str::random(10));
            if (! Payment::query()->where('payment_number', $number)->exists()) {
                return $number;
            }
        }

        return 'PAY-'.now()->format('Y').'-'.strtoupper(Str::uuid()->toString());
    }
}
