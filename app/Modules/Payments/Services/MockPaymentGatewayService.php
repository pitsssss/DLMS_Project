<?php

namespace App\Modules\Payments\Services;

/**
 * Mock provider: manual confirmation is handled by {@see ApplicationPaymentService::confirmMockPayment}.
 * This class exists so the Payments module has an explicit mock gateway alongside Stripe.
 */
final class MockPaymentGatewayService
{
    public function manualConfirmationEnabled(): bool
    {
        return true;
    }
}
