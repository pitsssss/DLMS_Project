<?php

namespace App\Modules\Payments\Services;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Payments\Support\StripeMoney;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as CheckoutSession;

class ApplicationPaymentService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PaymentProviderManager $providerManager,
        private readonly StripePaymentGatewayService $stripeGateway,
        private readonly AuditLogService $auditLogs
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
            ->with('fee')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{payment: Payment, checkout_url?: string, publishable_key?: string}
     */
    public function createPendingPayment(User $citizen, int $applicationId, ?array $metadata = null): array
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        if ($application->status !== ApplicationStatus::PaymentPending) {
            throw new ApiException('Payments can only be initiated when the application is awaiting payment.', 422);
        }

        $fee = $this->resolveApplicationFee($application);

        $existingCompleted = Payment::query()
            ->where('application_id', $application->id)
            ->where('fee_id', $fee->id)
            ->where('status', PaymentStatus::Completed)
            ->exists();

        if ($existingCompleted) {
            throw new ApiException('Payment already completed.', 422);
        }

        $provider = $this->providerManager->isStripe() ? 'stripe' : 'mock';

        $existingPending = Payment::query()
            ->where('application_id', $application->id)
            ->where('fee_id', $fee->id)
            ->where('status', PaymentStatus::Pending)
            ->first();

        if ($existingPending !== null) {
            if ($provider === 'stripe' && $existingPending->provider === 'stripe') {
                $checkoutUrl = $existingPending->metadata['checkout_url'] ?? null;
                if (is_string($checkoutUrl) && $checkoutUrl !== '' && $existingPending->provider_reference) {
                    return [
                        'payment' => $existingPending->load('fee'),
                        'checkout_url' => $checkoutUrl,
                        'publishable_key' => (string) config('payment.stripe.publishable_key'),
                    ];
                }
            }

            if ($existingPending->provider === $provider) {
                return ['payment' => $existingPending->load('fee')];
            }
        }

        return DB::transaction(function () use ($citizen, $application, $fee, $metadata, $provider) {
            $payment = Payment::query()->create([
                'payment_number' => $this->generateUniquePaymentNumber(),
                'user_id' => $citizen->id,
                'application_id' => $application->id,
                'fine_id' => null,
                'fee_id' => $fee->id,
                'payable_type' => null,
                'payable_id' => null,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
                'status' => PaymentStatus::Pending,
                'provider' => $provider,
                'provider_reference' => null,
                'paid_at' => null,
                'metadata' => $metadata,
            ]);

            if ($provider === 'stripe') {
                $session = $this->stripeGateway->createCheckoutSession($payment, $fee, $citizen, $application);
                $stripeMeta = $this->stripeSessionMetadata($session['session']);
                $payment->provider_reference = $session['session_id'];
                $payment->metadata = array_merge($payment->metadata ?? [], $stripeMeta);
                $payment->save();

                return [
                    'payment' => $payment->fresh(['fee']),
                    'checkout_url' => $session['url'],
                    'publishable_key' => (string) config('payment.stripe.publishable_key'),
                ];
            }

            return ['payment' => $payment->load('fee')];
        });
    }

    public function confirmMockPayment(User $citizen, int $applicationId, int $paymentId): Payment
    {
        if ($this->providerManager->isStripe()) {
            throw new ApiException('Manual confirmation is disabled for Stripe payments.', 400);
        }

        $this->requireOwnedApplication($citizen, $applicationId);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('application_id', $applicationId)
            ->where('user_id', $citizen->id)
            ->first();

        if ($payment === null) {
            throw new ApiException('Payment not found.', 404);
        }

        if ($payment->provider !== 'mock') {
            throw new ApiException('Manual confirmation is disabled for Stripe payments.', 400);
        }

        $application = LicenseApplication::query()
            ->whereKey($applicationId)
            ->where('citizen_id', $citizen->id)
            ->first();

        if ($application === null || $application->status !== ApplicationStatus::PaymentPending) {
            throw new ApiException('This application is not awaiting payment.', 422);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            throw new ApiException('This payment cannot be confirmed in its current state.', 422);
        }

        $mockRef = 'mock-'.Str::uuid()->toString();

        return $this->completePayment(
            $paymentId,
            $citizen,
            ['source' => 'mock_confirm'],
            $mockRef
        );
    }

    /**
     * Centralized successful completion (mock confirm, Stripe status poll, Stripe webhook).
     */
    public function completePayment(int $paymentId, ?User $actor, array $mergeMetadata = [], ?string $providerReference = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $actor, $mergeMetadata, $providerReference) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return $payment->fresh(['fee', 'application']);
            }

            if ($payment->status !== PaymentStatus::Pending) {
                throw new ApiException('Payment cannot be completed in its current state.', 422);
            }

            $application = LicenseApplication::query()
                ->whereKey($payment->application_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment->status = PaymentStatus::Completed;
            $payment->paid_at = now();
            if ($providerReference !== null && $providerReference !== '') {
                $payment->provider_reference = $providerReference;
            }
            $payment->metadata = array_merge($payment->metadata ?? [], $mergeMetadata);
            $payment->save();

            $this->auditLogs->log(
                $actor,
                'payment.completed',
                'payment',
                $payment->id,
                ['status' => PaymentStatus::Pending->value],
                ['status' => PaymentStatus::Completed->value, 'amount' => $payment->amount]
            );

            if ($application->status === ApplicationStatus::PaymentPending) {
                $this->applications->transitionStatus(
                    $application,
                    ApplicationStatus::PaymentCompleted,
                    $actor,
                    'Application fee payment completed.'
                );

                $application = $application->fresh();
                if ($application === null) {
                    throw new ApiException('Application not found.', 404);
                }

                $this->applications->transitionStatus(
                    $application,
                    ApplicationStatus::AppointmentPending,
                    $actor,
                    'Payment cleared. Awaiting appointment booking.'
                );
            }

            return $payment->fresh(['fee', 'application']);
        });
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
            ->with('fee')
            ->first();

        if ($payment === null) {
            throw new ApiException('Payment not found.', 404);
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

                if ($session->payment_status === 'paid' && $payment->status === PaymentStatus::Pending) {
                    if ((int) ($session->amount_total ?? 0) > 0) {
                        $this->verifyStripeAmountMatchesPayment($session, $payment);
                    }
                    $payment = $this->completePayment(
                        $payment->id,
                        $citizen,
                        array_merge($this->stripeSessionMetadata($session), [
                            'stripe_event_source' => 'status_poll',
                        ]),
                        $session->id
                    );
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable) {
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
        return Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $sessionId)
            ->first();
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

        return Payment::query()
            ->whereKey((int) $paymentId)
            ->where('provider', 'stripe')
            ->first();
    }

    public function markStripePaymentFailed(Payment $payment, array $mergeMetadata): void
    {
        DB::transaction(function () use ($payment, $mergeMetadata) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            $payment->status = PaymentStatus::Failed;
            $payment->metadata = array_merge($payment->metadata ?? [], $mergeMetadata);
            $payment->save();
        });
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

        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        try {
            if ((int) ($session->amount_total ?? 0) > 0) {
                $this->verifyStripeAmountMatchesPayment($session, $payment);
            }

            $actor = User::query()->find($payment->user_id);

            $meta = array_merge($this->stripeSessionMetadata($session), [
                'stripe_event_source' => 'webhook',
            ]);
            if ($stripeEventId !== null && $stripeEventId !== '') {
                $meta['stripe_event_id'] = $stripeEventId;
            }

            $this->completePayment($payment->id, $actor, $meta, $session->id);
        } catch (ApiException $e) {
            report($e);
        }
    }

    public function handleStripeCheckoutSessionFailed(CheckoutSession $session, string $reason, ?string $stripeEventId): void
    {
        $payment = $this->findStripePaymentBySessionId($session->id)
            ?? $this->findStripePaymentBySessionMetadata($session->metadata);

        if ($payment === null) {
            return;
        }

        $meta = [
            'stripe_session_status' => $session->status,
            'stripe_failure_reason' => $reason,
        ];
        if ($stripeEventId !== null && $stripeEventId !== '') {
            $meta['stripe_event_id'] = $stripeEventId;
        }

        $this->markStripePaymentFailed($payment, $meta);
    }

    private function verifyStripeAmountMatchesPayment(CheckoutSession $session, Payment $payment): void
    {
        $expectedMinor = StripeMoney::toStripeAmount((float) $payment->amount);
        $actual = (int) ($session->amount_total ?? 0);
        $currency = strtolower((string) ($session->currency ?? ''));
        $expectedCurrency = strtolower((string) config('payment.stripe.currency', 'usd'));

        if ($currency !== '' && $currency !== $expectedCurrency) {
            throw new ApiException('Stripe currency mismatch.', 422);
        }

        if ($actual > 0 && $actual !== $expectedMinor) {
            throw new ApiException('Stripe amount mismatch.', 422);
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
            throw new ApiException('Application not found.', 404);
        }

        return $application;
    }

    private function resolveApplicationFee(LicenseApplication $application): Fee
    {
        $fee = Fee::query()
            ->where('license_type_id', $application->license_type_id)
            ->where('service_type_id', $application->service_type_id)
            ->where('code', 'application_fee')
            ->where('is_active', true)
            ->first();

        if ($fee === null) {
            throw new ApiException('No application fee is configured for this license and service type.', 422);
        }

        return $fee;
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
