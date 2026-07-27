<?php

namespace App\Modules\Payments\Support;

use InvalidArgumentException;

/**
 * @deprecated Use Money::toMinorUnits() — kept as a thin adapter for existing call sites.
 */
final class StripeMoney
{
    public static function toStripeAmount(string $amount, ?string $currency = null): int
    {
        $currency ??= (string) config('payment.stripe.currency', 'usd');

        return Money::toMinorUnits($amount, strtoupper($currency));
    }
}
