<?php

namespace App\Modules\Payments\Services;

use App\Models\Fee;
use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Support\StripeMoney;
use App\Support\RequestLocaleResolver;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripePaymentGatewayService
{
    private ?StripeClient $client = null;

    public function manualConfirmationEnabled(): bool
    {
        return false;
    }

    /**
     * @return array{session_id: string, url: string, session: Session}
     */
    public function createCheckoutSession(Payment $payment, Fee $fee, User $user, LicenseApplication $application): array
    {
        $description = $application->application_number;
        $application->loadMissing('licenseType', 'serviceType');
        if ($application->licenseType) {
            $description .= ' — '.$application->licenseType->name;
        }
        if ($application->serviceType) {
            $description .= ' / '.$application->serviceType->name;
        }

        return $this->createCheckoutSessionWithContext(
            $payment,
            $user,
            __('messages.payments.stripe_product_application_fee'),
            $description,
            [
                'payment_id' => (string) $payment->id,
                'payment_number' => (string) $payment->payment_number,
                'payment_type' => 'application',
                'application_id' => (string) $application->id,
                'user_id' => (string) $user->id,
                'application_number' => $application->application_number,
            ]
        );
    }

    /**
     * @return array{session_id: string, url: string, session: Session}
     */
    public function createFineCheckoutSession(Payment $payment, Fine $fine, User $user): array
    {
        $description = __('messages.payments.stripe_description_fine', [
            'fine_id' => $fine->id,
            'payment_number' => $payment->payment_number,
        ]);

        $locale = $this->checkoutDisplayLocale();

        return $this->createCheckoutSessionWithContext(
            $payment,
            $user,
            __('messages.payments.stripe_product_fine'),
            $description,
            [
                'payment_id' => (string) $payment->id,
                'payment_number' => (string) $payment->payment_number,
                'payment_type' => 'fine',
                'fine_id' => (string) $fine->id,
                'user_id' => (string) $user->id,
                'citizen_id' => (string) $fine->citizen_id,
            ],
            $this->buildFineSuccessUrl($locale),
            $this->buildFineCancelUrl($locale)
        );
    }

    /**
     * Absolute Fine Stripe success URL with Checkout Session placeholder + display locale.
     * Must remain unencoded as `{CHECKOUT_SESSION_ID}` for Stripe substitution.
     */
    public function buildFineSuccessUrl(string $locale): string
    {
        $locale = $this->normalizeCheckoutLocale($locale);
        $base = route('payment.return.success', absolute: true);

        return $base.'?session_id={CHECKOUT_SESSION_ID}&lang='.rawurlencode($locale);
    }

    /**
     * Absolute Fine Stripe cancel URL with display locale.
     */
    public function buildFineCancelUrl(string $locale): string
    {
        $locale = $this->normalizeCheckoutLocale($locale);

        return route('payment.return.cancel', ['lang' => $locale], absolute: true);
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{session_id: string, url: string, session: Session}
     */
    private function createCheckoutSessionWithContext(
        Payment $payment,
        User $user,
        string $productName,
        string $description,
        array $metadata,
        ?string $successUrlOverride = null,
        ?string $cancelUrlOverride = null
    ): array {
        if ((string) $payment->provider !== 'stripe') {
            throw new \InvalidArgumentException('Stripe Checkout requires payment.provider=stripe.');
        }

        $currency = strtolower((string) config('payment.stripe.currency', 'usd'));
        $successUrl = $successUrlOverride ?? (string) config('payment.stripe.success_url');
        $cancelUrl = $cancelUrlOverride ?? (string) config('payment.stripe.cancel_url');

        if ($successUrl === '' || $cancelUrl === '') {
            throw new \RuntimeException('Stripe success_url and cancel_url must be configured.');
        }

        $paymentCurrency = strtoupper((string) $payment->currency);
        if ($paymentCurrency !== strtoupper($currency)) {
            throw new \RuntimeException('Stripe currency does not match payment currency.');
        }

        $unitAmount = StripeMoney::toStripeAmount((string) $payment->amount, $paymentCurrency);

        try {
            $session = $this->client()->checkout->sessions->create(
                [
                    'mode' => 'payment',
                    'payment_method_types' => ['card'],
                    'line_items' => [
                        [
                            'price_data' => [
                                'currency' => strtolower($paymentCurrency),
                                'product_data' => [
                                    'name' => $productName,
                                    'description' => $description,
                                ],
                                'unit_amount' => $unitAmount,
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'customer_email' => $user->email,
                    'client_reference_id' => (string) $payment->id,
                    'metadata' => $metadata,
                ],
                [
                    'idempotency_key' => 'dlms-payment-'.$payment->payment_number,
                ]
            );
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe Checkout could not be created.', 0, $e);
        }

        return [
            'session_id' => $session->id,
            'url' => (string) $session->url,
            'session' => $session,
        ];
    }

    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return $this->client()->checkout->sessions->retrieve($sessionId, []);
    }

    private function checkoutDisplayLocale(): string
    {
        return $this->normalizeCheckoutLocale((string) app()->getLocale());
    }

    private function normalizeCheckoutLocale(string $locale): string
    {
        $resolver = app(RequestLocaleResolver::class);
        $normalized = strtolower(trim($locale));

        if ($resolver->isSupported($normalized)) {
            return $normalized;
        }

        return $resolver->defaultLocale();
    }

    private function client(): StripeClient
    {
        if ($this->client instanceof StripeClient) {
            return $this->client;
        }

        $secret = (string) config('payment.stripe.secret_key');
        if ($secret === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $this->client = new StripeClient(['api_key' => $secret]);

        return $this->client;
    }
}
