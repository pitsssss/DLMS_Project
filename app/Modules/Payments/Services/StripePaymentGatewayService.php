<?php

namespace App\Modules\Payments\Services;

use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Support\StripeMoney;
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
        $currency = strtolower((string) config('payment.stripe.currency', 'usd'));
        $successUrl = (string) config('payment.stripe.success_url');
        $cancelUrl = (string) config('payment.stripe.cancel_url');

        if ($successUrl === '' || $cancelUrl === '') {
            throw new \RuntimeException('Stripe success_url and cancel_url must be configured.');
        }

        $description = $application->application_number;
        $application->loadMissing('licenseType', 'serviceType');
        if ($application->licenseType) {
            $description .= ' — '.$application->licenseType->name;
        }
        if ($application->serviceType) {
            $description .= ' / '.$application->serviceType->name;
        }

        $unitAmount = StripeMoney::toStripeAmount((float) $fee->amount);

        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $currency,
                            'product_data' => [
                                'name' => 'DLMS Application Fee',
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
                'metadata' => [
                    'payment_id' => (string) $payment->id,
                    'application_id' => (string) $application->id,
                    'user_id' => (string) $user->id,
                    'application_number' => $application->application_number,
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe Checkout could not be created: '.$e->getMessage(), 0, $e);
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
