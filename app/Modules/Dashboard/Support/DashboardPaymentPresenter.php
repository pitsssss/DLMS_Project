<?php

namespace App\Modules\Dashboard\Support;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use App\Modules\Payments\Support\Money;

final class DashboardPaymentPresenter
{
    public static function money(mixed $amount): string
    {
        return Money::format((string) $amount);
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function paymentStatus(?PaymentStatus $status): array
    {
        $value = $status?->value ?? PaymentStatus::Pending->value;

        return [
            'value' => $value,
            'label' => Msg::get('payments.statuses.'.$value),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function provider(string $provider): array
    {
        $key = 'messages.payments.providers.'.$provider;
        $label = Msg::get('payments.providers.'.$provider);

        return [
            'value' => $provider,
            'label' => $label,
        ];
    }

    /**
     * @return array{value: string, label: string}|null
     */
    public static function applicationStatus(mixed $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $value = $status instanceof ApplicationStatus ? $status->value : (string) $status;

        return [
            'value' => $value,
            'label' => EmployeeMessageTranslator::get('employee.statuses.'.$value),
        ];
    }

    public static function currencyLabel(string $currency): string
    {
        $code = strtoupper(trim($currency));

        return match ($code) {
            'USD' => Msg::get('payments.currencies.usd'),
            default => $code,
        };
    }
}
