<?php

namespace App\Modules\Payments\Support;

/**
 * Fee catalog metadata: supported codes, scope rules, and initial seed defaults.
 *
 * Runtime pricing is authoritative in the `fees` database table. Demo amounts here
 * are used only when creating missing catalog rows during seeding.
 */
final class ApplicationFeeCatalog
{
    public const CURRENCY = 'USD';

    public const SCOPE_APPLICATION = 'application';

    public const SCOPE_SERVICE = 'service';

    public const SCOPE_TEST = 'test';

    /**
     * Application-service fees collected via the payment module.
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
     * Initial/demo amounts for seeding missing rows only.
     *
     * @var array<string, string> fee_code => decimal major-unit amount
     */
    public const SEED_DEFAULT_AMOUNTS = [
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
     * Scope and linkage rules per supported fee code.
     *
     * @var array<string, array{
     *   scope: string,
     *   service_code?: string,
     *   test_code?: string,
     *   requires_license_type: bool
     * }>
     */
    public const CODE_DEFINITIONS = [
        'application_fee' => [
            'scope' => self::SCOPE_APPLICATION,
            'service_code' => 'new_license',
            'requires_license_type' => true,
        ],
        'renewal_fee' => [
            'scope' => self::SCOPE_SERVICE,
            'service_code' => 'renew_license',
            'requires_license_type' => false,
        ],
        'lost_replacement_fee' => [
            'scope' => self::SCOPE_SERVICE,
            'service_code' => 'lost_replacement',
            'requires_license_type' => false,
        ],
        'damaged_replacement_fee' => [
            'scope' => self::SCOPE_SERVICE,
            'service_code' => 'damaged_replacement',
            'requires_license_type' => false,
        ],
        'unblock_fee' => [
            'scope' => self::SCOPE_SERVICE,
            'service_code' => 'license_unblock',
            'requires_license_type' => false,
        ],
        'vision_test_fee' => [
            'scope' => self::SCOPE_TEST,
            'test_code' => 'vision',
            'requires_license_type' => false,
        ],
        'theory_test_fee' => [
            'scope' => self::SCOPE_TEST,
            'test_code' => 'theory',
            'requires_license_type' => false,
        ],
        'practical_test_fee' => [
            'scope' => self::SCOPE_TEST,
            'test_code' => 'practical',
            'requires_license_type' => false,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function catalogCodes(): array
    {
        return array_keys(self::CODE_DEFINITIONS);
    }

    /**
     * @return list<string>
     */
    public static function payableCodes(): array
    {
        return self::APPLICATION_PAYABLE_CODES;
    }

    public static function isSupportedCode(string $code): bool
    {
        return array_key_exists(trim($code), self::CODE_DEFINITIONS);
    }

    public static function isApplicationPayable(string $code): bool
    {
        return in_array(trim($code), self::APPLICATION_PAYABLE_CODES, true);
    }

    /**
     * @return array{scope: string, service_code?: string, test_code?: string, requires_license_type: bool}|null
     */
    public static function definitionFor(string $code): ?array
    {
        $code = trim($code);

        return self::CODE_DEFINITIONS[$code] ?? null;
    }

    public static function seedDefaultAmount(string $code): string
    {
        $code = trim($code);
        if (! array_key_exists($code, self::SEED_DEFAULT_AMOUNTS)) {
            throw new \InvalidArgumentException('Unknown application fee code: '.$code);
        }

        return Money::format(self::SEED_DEFAULT_AMOUNTS[$code]);
    }

    /**
     * @deprecated Use seedDefaultAmount() for seeding; runtime amounts come from DB.
     */
    public static function amountFor(string $code): string
    {
        return self::seedDefaultAmount($code);
    }

    public static function seedDefaultName(string $code): string
    {
        return match (trim($code)) {
            'application_fee' => 'رسوم تقديم الطلب',
            'renewal_fee' => 'رسوم تجديد الرخصة',
            'lost_replacement_fee' => 'رسوم بدل فاقد',
            'damaged_replacement_fee' => 'رسوم بدل تالف',
            'unblock_fee' => 'رسوم فك حظر الرخصة',
            'vision_test_fee' => 'رسوم اختبار النظر',
            'theory_test_fee' => 'رسوم الاختبار النظري',
            'practical_test_fee' => 'رسوم الاختبار العملي',
            default => trim($code),
        };
    }

    /**
     * @return list<string>
     */
    public static function allowedCurrencies(): array
    {
        return [self::CURRENCY];
    }
}
