<?php

namespace App\Modules\Dashboard\Requests\Concerns;

use App\Modules\Payments\Support\Money;
use App\Support\Msg;
use InvalidArgumentException;

trait ValidatesDashboardFeeAmount
{
    /**
     * @return array<int, mixed>
     */
    protected function feeAmountRules(bool $required = true): array
    {
        $rules = [];
        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'sometimes';
        }

        $rules[] = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_float($value)) {
                $fail(Msg::get('fees.validation.float_not_allowed'));

                return;
            }

            if (! is_string($value) && ! is_int($value)) {
                $fail(Msg::get('fees.validation.amount_format'));

                return;
            }

            $raw = trim((string) $value);
            if ($raw === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                $fail(Msg::get('fees.validation.amount_format'));

                return;
            }

            try {
                $formatted = Money::format($raw);
                if (bccomp($formatted, '0', 2) <= 0) {
                    $fail(Msg::get('fees.validation.amount_positive'));
                }
            } catch (InvalidArgumentException) {
                $fail(Msg::get('fees.validation.amount_format'));
            }
        };

        return $rules;
    }
}
