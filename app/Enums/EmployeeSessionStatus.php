<?php

namespace App\Enums;

enum EmployeeSessionStatus: string
{
    case Active = 'active';
    case Idle = 'idle';
    case Expired = 'expired';
    case LoggedOut = 'logged_out';
    case Revoked = 'revoked';

    public function label(): string
    {
        return __('messages.employee_sessions.status.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases()
        );
    }
}
