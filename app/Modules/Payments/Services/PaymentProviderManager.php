<?php

namespace App\Modules\Payments\Services;

final class PaymentProviderManager
{
    public function current(): string
    {
        return strtolower((string) config('payment.provider', 'mock'));
    }

    public function isMock(): bool
    {
        return $this->current() !== 'stripe';
    }

    public function isStripe(): bool
    {
        return $this->current() === 'stripe';
    }
}
