<?php

namespace App\Modules\Dashboard\Support;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Support\EmployeeMessageTranslator;

final class DashboardPaymentPresenter
{
    public static function money(mixed $amount): string
    {
        return number_format((float) (string) $amount, 2, '.', '');
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function paymentStatus(?PaymentStatus $status): array
    {
        $value = $status?->value ?? PaymentStatus::Pending->value;

        return [
            'value' => $value,
            'label' => __('messages.payments.statuses.'.$value),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function provider(string $provider): array
    {
        $key = 'messages.payments.providers.'.$provider;
        $label = __($key);

        return [
            'value' => $provider,
            'label' => $label === $key ? $provider : $label,
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
}
