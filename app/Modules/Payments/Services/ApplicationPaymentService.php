<?php

namespace App\Modules\Payments\Services;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Payments\Support\ApplicationFeeResolver;
use App\Modules\Payments\Support\Money;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as CheckoutSession;
use Throwable;

class ApplicationPaymentService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PaymentProviderManager $providerManager,
        private readonly StripePaymentGatewayService $stripeGateway,
        private readonly ApplicationFeeResolver $feeResolver,
        private readonly AuditLogService $auditLogs,
        private readonly PaymentLifecycleService $lifecycle,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly PaymentGatewayEventService $gatewayEvents,
    ) {}

    /**
     * @return array{fee: Fee, application: LicenseApplication}
     */
    public function getFeeForApplication(User $citizen, int $applicationId): array
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);
        $fee = $this->resolveApplicationFee($application);

        return ['fee' => $fee, 'application' => $application];
    }

    /**
     * @return Collection<int, Payment>
     */
    public function listForApplication(User $citizen, int $applicationId): Collection
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        return Payment::query()
            ->where('application_id', $application->id)
            ->where('user_id', $citizen->id)
            ->whereNull('fine_id')
            ->with('fee')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{payment: Payment, checkout_url?: string, publishable_key?: string}
     */
    public function createPendingPayment(User $citizen, int $applicationId, ?array $metadata = null): array
    {
        $provider = $this->providerManager->isStripe() ? 'stripe' : 'mock';

        $prepared = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            /** @var array{action: string, payment: Payment} $prepared */
            $prepared = DB::transaction(function () use ($citizen, $applicationId, $metadata, $provider) {
                $application = LicenseApplication::query()
                    ->whereKey($applicationId)
                    ->where('citizen_id', $citizen->id)
                    ->lockForUpdate()
                    ->first();

                if ($application === null) {
                    throw new ApiException('messages.applications.not_found', 404);
                }

                if ($application->status !== ApplicationStatus::PaymentPending) {
                    throw new ApiException('messages.payments.not_awaiting_payment', 422);
                }

                $fee = $this->resolveApplicationFee($application);
                $obligationKey = Payment::obligationKey($application->id, $fee->id);

                $existingCompleted = Payment::query()
                    ->where(function ($q) use ($obligationKey, $application, $fee): void {
                        $q->where('settled_obligation_key', $obligationKey)
                            ->orWhere(function ($inner) use ($application, $fee): void {
                                $inner->where('application_id', $application->id)
                                    ->where('fee_id', $fee->id)
                                    ->whereNull('fine_id')
                                    ->where('status', PaymentStatus::Completed);
                            });
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($existingCompleted) {
                    throw new ApiException('messages.payments.already_completed', 422);
                }

                $existingActive = Payment::query()
                    ->where('active_obligation_key', $obligationKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingActive !== null) {
                    if ((string) $existingActive->provider === $provider) {
                        return ['action' => 'reuse', 'payment' => $existingActive->load('fee')];
                    }

                    return ['action' => 'retire', 'payment' => $existingActive];
                }

                $payment = Payment::query()->create([
                    'payment_number' => $this->generateUniquePaymentNumber(),
                    'user_id' => $citizen->id,
                    'application_id' => $application->id,
                    'fine_id' => null,
                    'fee_id' => $fee->id,
                    'payable_type' => null,
                    'payable_id' => null,
                    'amount' => Money::format((string) $fee->amount),
                    'currency' => strtoupper((string) $fee->currency),
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
                        'application_id' => $application->id,
                        'source' => 'citizen',
                    ]
                );

                return ['action' => 'created', 'payment' => $payment->load('fee')];
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

        // Reuse existing Stripe checkout when still valid.
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

        try {
            $session = $this->stripeGateway->createCheckoutSession(
                $payment,
                $payment->fee ?? Fee::query()->findOrFail($payment->fee_id),
                $citizen,
                LicenseApplication::query()->findOrFail($payment->application_id)
            );
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
                    'source' => 'citizen',
                ]
            );
        });

        return [
            'payment' => $payment->fresh(['fee']),
            'checkout_url' => $session['url'],
            'publishable_key' => (string) config('payment.stripe.publishable_key'),
        ];
    }

    public function confirmMockPayment(User $citizen, int $applicationId, int $paymentId): Payment
    {
        if ($this->providerManager->isStripe()) {
            throw new ApiException('messages.payments.manual_confirm_disabled', 400);
        }

        $this->requireOwnedApplication($citizen, $applicationId);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('application_id', $applicationId)
            ->where('user_id', $citizen->id)
            ->whereNull('fine_id')
            ->first();

        if ($payment === null) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        if ($payment->provider !== 'mock') {
            throw new ApiException('messages.payments.manual_confirm_disabled', 400);
        }

        $application = LicenseApplication::query()
            ->whereKey($applicationId)
            ->where('citizen_id', $citizen->id)
            ->first();

        if ($application === null || $application->status !== ApplicationStatus::PaymentPending) {
            throw new ApiException('messages.payments.not_awaiting_payment', 422);
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
     * @deprecated Prefer PaymentLifecycleService::completeVerifiedPayment
     *
     * @param  array<string, mixed>  $mergeMetadata
     */
    public function completePayment(int $paymentId, ?User $actor, array $mergeMetadata = [], ?string $providerReference = null): Payment
    {
        return $this->lifecycle->completeVerifiedPayment(
            $paymentId,
            $actor,
            $mergeMetadata,
            $providerReference,
            'system'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentStatus(User $citizen, int $applicationId, int $paymentId): array
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('application_id', $application->id)
            ->where('user_id', $citizen->id)
            ->whereNull('fine_id')
            ->with('fee')
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
        $application->refresh();

        return [
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            'application' => [
                'id' => $application->id,
                'status' => $application->status->value,
            ],
            'stripe' => [
                'payment_status' => $stripePaymentStatus,
                'session_status' => $stripeSessionStatus,
            ],
        ];
    }

    public function findStripePaymentBySessionId(string $sessionId): ?Payment
    {
        $payment = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $sessionId)
            ->first();

        return $payment !== null && $payment->isSupportedPayable() ? $payment : null;
    }

    public function findStripePaymentBySessionMetadata(?object $metadata): ?Payment
    {
        if ($metadata === null) {
            return null;
        }

        $paymentId = $metadata->payment_id ?? null;
        if ($paymentId === null || $paymentId === '') {
            return null;
        }

        $payment = Payment::query()
            ->whereKey((int) $paymentId)
            ->where('provider', 'stripe')
            ->first();

        return $payment !== null && $payment->isSupportedPayable() ? $payment : null;
    }

    public function markStripePaymentFailed(Payment $payment, array $mergeMetadata, PaymentFailureCode $code = PaymentFailureCode::AsyncPaymentFailed): void
    {
        $this->lifecycle->markFailed($payment->id, $code, $mergeMetadata, null, 'gateway');
    }

    public function completeStripePaymentFromSession(CheckoutSession $session, ?string $stripeEventId): void
    {
        $payment = $this->findStripePaymentBySessionId($session->id)
            ?? $this->findStripePaymentBySessionMetadata($session->metadata);

        if ($payment === null) {
            return;
        }

        if ($payment->status === PaymentStatus::Completed) {
            return;
        }

        $mismatch = $this->reconciliation->validateStripeSession($session, $payment);
        if ($mismatch !== null) {
            $this->lifecycle->markUnderVerification(
                $payment->id,
                $mismatch,
                array_merge($this->stripeSessionMetadata($session), [
                    'stripe_event_source' => 'webhook',
                    'stripe_event_id' => $stripeEventId,
                ]),
                null,
                'gateway'
            );

            return;
        }

        $actor = User::query()->find($payment->user_id);
        $meta = array_merge($this->stripeSessionMetadata($session), [
            'stripe_event_source' => 'webhook',
        ]);
        if ($stripeEventId !== null && $stripeEventId !== '') {
            $meta['stripe_event_id'] = $stripeEventId;
        }

        $this->lifecycle->completeVerifiedPayment($payment->id, $actor, $meta, $session->id, 'gateway');
    }

    public function handleStripeCheckoutSessionFailed(CheckoutSession $session, string $reason, ?string $stripeEventId): void
    {
        $payment = $this->findStripePaymentBySessionId($session->id)
            ?? $this->findStripePaymentBySessionMetadata($session->metadata);

        if ($payment === null) {
            return;
        }

        $code = $reason === 'expired'
            ? PaymentFailureCode::SessionExpired
            : PaymentFailureCode::AsyncPaymentFailed;

        $meta = [
            'stripe_session_status' => $session->status,
            'stripe_failure_reason' => $reason,
        ];
        if ($stripeEventId !== null && $stripeEventId !== '') {
            $meta['stripe_event_id'] = $stripeEventId;
        }

        $this->markStripePaymentFailed($payment, $meta, $code);
    }

    public function gatewayEvents(): PaymentGatewayEventService
    {
        return $this->gatewayEvents;
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

    private function requireOwnedApplication(User $citizen, int $applicationId): LicenseApplication
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        return $application;
    }

    private function resolveApplicationFee(LicenseApplication $application): Fee
    {
        return $this->feeResolver->resolve($application);
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
