<?php

namespace App\Modules\Payments\Support;

/**
 * Centralized application-fee currency and demo USD amounts.
 *
 * These are project/demo values for development and testing. Replace with
 * business-approved amounts before production go-live. They are NOT derived
 * from any exchange-rate conversion.
 */
final class ApplicationFeeCatalog
{
    public const CURRENCY = 'USD';

    /**
     * Application-service fees that may be collected via the payment module.
     *
     * @var list<string>
     */
    public const APPLICATION_PAYABLE_CODES = [
        'application_fee',
        'renewal_fee',
        'lost_replacement_fee',
        'damaged_replacement_fee',
        'unblock_fee',
    ];

    /**
     * All catalogued fee codes including test fees (not charged via application payment today).
     *
     * @var array<string, string> fee_code => decimal major-unit amount
     */
    public const DEMO_AMOUNTS = [
        'application_fee' => '50.00',
        'renewal_fee' => '40.00',
        'lost_replacement_fee' => '25.00',
        'damaged_replacement_fee' => '25.00',
        'unblock_fee' => '30.00',
        'vision_test_fee' => '10.00',
        'theory_test_fee' => '15.00',
        'practical_test_fee' => '20.00',
    ];

    /**
     * @return list<string>
     */
    public static function catalogCodes(): array
    {
        return array_keys(self::DEMO_AMOUNTS);
    }

    public static function amountFor(string $code): string
    {
        $code = trim($code);
        if (! array_key_exists($code, self::DEMO_AMOUNTS)) {
            throw new \InvalidArgumentException('Unknown application fee code: '.$code);
        }

        return Money::format(self::DEMO_AMOUNTS[$code]);
    }

    public static function isApplicationPayable(string $code): bool
    {
        return in_array($code, self::APPLICATION_PAYABLE_CODES, true);
    }
}
