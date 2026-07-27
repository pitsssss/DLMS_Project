<?php

namespace App\Modules\Payments\Support;

/**
 * @deprecated Use Money::toMinorUnits() — kept as a thin adapter for existing call sites.
 */
final class StripeMoney
{
    public static function toStripeAmount(float|string $amount, ?string $currency = null): int
    {
        if (is_float($amount)) {
            // Legacy signature accepted float; convert via decimal string only.
            $amount = number_format($amount, 2, '.', '');
        }

        $currency ??= (string) config('payment.stripe.currency', 'usd');

        return Money::toMinorUnits((string) $amount, strtoupper($currency));
    }
}
