<?php

namespace App\Modules\Payments\Support;

use InvalidArgumentException;

/**
 * Exact decimal-string money helpers. Never uses binary float arithmetic for comparisons.
 */
final class Money
{
    /**
     * Currency ISO code => decimal exponent (minor units).
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        'SYP' => 2,
        'USD' => 2,
        'EUR' => 2,
        'JPY' => 0,
        'KWD' => 3,
    ];

    public static function normalize(string|int|float $amount): string
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException('Float amounts are not allowed.');
        }

        $raw = is_int($amount) ? (string) $amount : trim((string) $amount);
        if ($raw === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        if (! str_contains($raw, '.')) {
            return $raw.'.00';
        }

        [$whole, $fraction] = explode('.', $raw, 2);
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return $whole.'.'.$fraction;
    }

    public static function format(string|int $amount, int $scale = 2): string
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        return bcadd($amount, '0', $scale);
    }

    public static function equals(string $left, string $right, int $scale = 2): bool
    {
        return bccomp(self::format($left, $scale), self::format($right, $scale), $scale) === 0;
    }

    public static function exponentFor(string $currency): int
    {
        $code = strtoupper(trim($currency));

        if (! array_key_exists($code, self::EXPONENTS)) {
            throw new InvalidArgumentException('Unsupported currency exponent: '.$code);
        }

        return self::EXPONENTS[$code];
    }

    /**
     * Convert a decimal major-unit amount to integer minor units without float.
     */
    public static function toMinorUnits(string $amount, string $currency): int
    {
        $exponent = self::exponentFor($currency);
        $normalized = trim($amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        $negative = str_starts_with($normalized, '-');
        if ($negative) {
            $normalized = substr($normalized, 1);
        }

        if (! str_contains($normalized, '.')) {
            $whole = $normalized;
            $fraction = '';
        } else {
            [$whole, $fraction] = explode('.', $normalized, 2);
        }

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException('Amount precision exceeds currency exponent.');
        }

        $fraction = str_pad($fraction, $exponent, '0');
        $minor = $whole.($exponent > 0 ? $fraction : '');
        $minor = ltrim($minor, '0');
        $minor = $minor === '' ? '0' : $minor;

        if (! ctype_digit($minor)) {
            throw new InvalidArgumentException('Invalid minor unit conversion.');
        }

        $value = (int) $minor;

        return $negative ? -$value : $value;
    }

    public static function sum(iterable $amounts, int $scale = 2): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, self::format((string) $amount, $scale), $scale);
        }

        return self::format($total, $scale);
    }
}
