<?php

namespace App\Enums;

enum PushDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case InvalidToken = 'invalid_token';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::InvalidToken => true,
            default => false,
        };
    }
}
