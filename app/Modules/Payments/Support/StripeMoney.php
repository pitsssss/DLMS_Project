<?php

namespace App\Modules\Payments\Support;

final class StripeMoney
{
    /**
     * Convert a decimal major-unit amount to Stripe's smallest currency unit (e.g. USD cents).
     */
    public static function toStripeAmount(float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
